<?php

namespace App\Http\Controllers\API\Teller;

use App\Http\Controllers\Controller;
use App\Http\Resources\DailyReportResource;
use App\Http\Resources\TransactionResource;
use App\Models\DailyReport;
use App\Services\DailyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class DailyReportController extends Controller
{
    public function __construct(private DailyReportService $reports)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $reports = $request->user()->dailyReports()
            ->with('supervisor')
            ->latest('report_date')
            ->paginate($this->perPage($request));

        return DailyReportResource::collection($reports);
    }

    /**
     * The printable cash closing draft: live totals plus every transaction of the day.
     */
    public function preview(Request $request): JsonResponse
    {
        $date = $this->dateFrom($request);
        $teller = $request->user();

        $transactions = $teller->transactions()
            ->with('customer')
            ->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->oldest('id')
            ->get();

        $summary = $this->reports->summarize($teller, $date);
        $report = $this->reports->findFor($teller, $date)?->load('supervisor');

        return response()->json([
            'data' => [
                'report_date' => $date->toDateString(),
                'teller' => ['id' => $teller->id, 'full_name' => $teller->full_name],
                ...$summary,
                'net_cash' => $summary['total_deposit'] - $summary['total_withdrawal'],
                'counter_closed' => $this->reports->isCounterClosed($teller, $date),
                'report' => $report ? new DailyReportResource($report) : null,
                'transactions' => TransactionResource::collection($transactions),
            ],
        ]);
    }

    public function store(Request $request): DailyReportResource
    {
        return new DailyReportResource($this->reports->submit($request->user(), $this->dateFrom($request)));
    }

    public function show(Request $request, DailyReport $dailyReport): DailyReportResource
    {
        abort_unless($dailyReport->teller_id === $request->user()->id, 404);

        return new DailyReportResource($dailyReport->load('supervisor'));
    }

    private function dateFrom(Request $request): Carbon
    {
        $request->validate(['date' => ['nullable', 'date', 'before_or_equal:today']]);

        return Carbon::parse($request->input('date', 'today'))->startOfDay();
    }
}
