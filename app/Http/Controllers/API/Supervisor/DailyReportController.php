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

/**
 * Verification of teller shifts, one report at a time (no bulk approval).
 */
class DailyReportController extends Controller
{
    public function __construct(private DailyReportService $reports)
    {
    }

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

        return DailyReportResource::collection($reports->through(fn ($report) => $this->reports->withLiveTotals($report)));
    }

    /**
     * One report with its cash counts and the transactions it summarizes, so the
     * supervisor can compare it with the slips and the physical cash.
     */
    public function show(DailyReport $dailyReport): DailyReportResource
    {
        $this->reports->withLiveTotals($dailyReport)
            ->load(['teller', 'supervisor', 'openingCounts', 'closingCounts', 'cashTransfer']);

        return (new DailyReportResource($dailyReport))->additional([
            'transactions' => TransactionResource::collection($dailyReport->transactions()->with('customer')->oldest('id')->get()),
        ]);
    }

    /**
     * Approving also books the counted cash from the teller's drawer to the vault.
     */
    public function approve(ReviewRequest $request, DailyReport $dailyReport): DailyReportResource
    {
        $report = $this->reports->approve($dailyReport, $request->user(), $request->validated('note'));

        return new DailyReportResource($report->load(['teller', 'supervisor', 'cashTransfer']));
    }

    public function hold(ReviewRequest $request, DailyReport $dailyReport): DailyReportResource
    {
        $report = $this->reports->hold($dailyReport, $request->user(), $request->validated('note'));

        return new DailyReportResource($report->load(['teller', 'supervisor']));
    }

    public function reject(ReviewRequest $request, DailyReport $dailyReport): DailyReportResource
    {
        $report = $this->reports->reject($dailyReport, $request->user(), $request->validated('note'));

        return new DailyReportResource($report->load(['teller', 'supervisor']));
    }
}
