<?php

namespace App\Http\Controllers\API\Supervisor;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Supervisor\ReviewRequest;
use App\Http\Resources\DailyReportResource;
use App\Http\Resources\TransactionResource;
use App\Models\DailyReport;
use App\Services\DailyReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DailyReportController extends Controller
{
    public function __construct(private DailyReportService $reports)
    {
    }

    /**
     * Reports waiting for (or past) review. Drafts are never stored, so every
     * listed report has been submitted at least once.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(ReportStatus::class)],
            'date' => ['nullable', 'date'],
            'teller_id' => ['nullable', 'integer'],
        ]);

        $reports = DailyReport::query()
            ->with(['teller', 'supervisor'])
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->date('date'), fn ($query, $date) => $query->whereDate('report_date', $date))
            ->when($request->input('teller_id'), fn ($query, $tellerId) => $query->where('teller_id', $tellerId))
            ->latest('report_date')
            ->latest('submitted_at')
            ->paginate($this->perPage($request));

        return DailyReportResource::collection($reports);
    }

    /**
     * One report together with the transactions it summarizes, for verification.
     */
    public function show(DailyReport $dailyReport): DailyReportResource
    {
        $transactions = $dailyReport->teller->transactions()
            ->with('customer')
            ->whereBetween('created_at', [$dailyReport->report_date->copy()->startOfDay(), $dailyReport->report_date->copy()->endOfDay()])
            ->oldest('id')
            ->get();

        return (new DailyReportResource($dailyReport->load(['teller', 'supervisor'])))
            ->additional(['transactions' => TransactionResource::collection($transactions)]);
    }

    public function approve(ReviewRequest $request, DailyReport $dailyReport): DailyReportResource
    {
        $report = $this->reports->approve($dailyReport, $request->user(), $request->validated('note'));

        return new DailyReportResource($report->load(['teller', 'supervisor']));
    }

    public function reject(ReviewRequest $request, DailyReport $dailyReport): DailyReportResource
    {
        $report = $this->reports->reject($dailyReport, $request->user(), $request->validated('note'));

        return new DailyReportResource($report->load(['teller', 'supervisor']));
    }
}
