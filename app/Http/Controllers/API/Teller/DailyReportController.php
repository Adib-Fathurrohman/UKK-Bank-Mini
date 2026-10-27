<?php

namespace App\Http\Controllers\API\Teller;

use App\Http\Controllers\Controller;
use App\Http\Resources\DailyReportResource;
use App\Http\Resources\TransactionResource;
use App\Models\DailyReport;
use App\Services\DailyReportService;
use App\Services\PdfReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The teller's own shift history.
 */
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

        return DailyReportResource::collection($reports->through(fn ($report) => $this->reports->withLiveTotals($report)));
    }

    /**
     * Print the closing report; locked until the shift is closed with matching cash.
     */
    public function export(Request $request, DailyReport $dailyReport, PdfReportService $pdf): Response
    {
        abort_unless($dailyReport->teller_id === $request->user()->id, 404);

        return $pdf->dailyReport($dailyReport);
    }

    public function show(Request $request, DailyReport $dailyReport): DailyReportResource
    {
        abort_unless($dailyReport->teller_id === $request->user()->id, 404);

        $this->reports->withLiveTotals($dailyReport)->load(['supervisor', 'openingCounts', 'closingCounts', 'cashTransfer']);

        return (new DailyReportResource($dailyReport))->additional([
            'transactions' => TransactionResource::collection($dailyReport->transactions()->with('customer')->oldest('id')->get()),
        ]);
    }
}
