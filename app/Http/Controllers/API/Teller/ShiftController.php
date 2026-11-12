<?php

namespace App\Http\Controllers\API\Teller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teller\CashCountRequest;
use App\Http\Resources\DailyReportResource;
use App\Http\Resources\TransactionResource;
use App\Models\DailyReport;
use App\Services\CashDrawerService;
use App\Services\DailyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The logged-in teller's current shift: open it with the opening cash count,
 * follow the running totals, and close it with the physical cash count.
 */
class ShiftController extends Controller
{
    public function __construct(private DailyReportService $reports)
    {
    }

    /**
     * The active shift (open or rejected) with live totals and its transactions,
     * which doubles as the on-screen closing draft. Null when no shift is running.
     */
    public function show(Request $request, CashDrawerService $drawerService): JsonResponse
    {
        $teller = $request->user();
        $shift = $this->reports->activeShift($teller);

        if (! $shift) {
            return response()->json([
                'data' => null,
                'can_transact' => false,
                'closed_today' => DailyReport::where('teller_id', $teller->id)->whereDate('report_date', today())->exists(),
            ]);
        }

        $this->reports->withLiveTotals($shift)->load(['openingCounts', 'closingCounts', 'supervisor']);

        $transactions = $shift->transactions()->with(['customer', 'cash'])->oldest('id')->get();

        $drawer = $shift->cash_tracked ? $drawerService->contents($shift) : null;

        return (new DailyReportResource($shift))
            ->additional([
                'can_transact' => $shift->report_date->isToday(),
                // What the drawer should hold now, per denomination (null for an untracked shift).
                'drawer' => $drawer === null ? null : collect($drawer)->map(fn ($quantity, $denomination) => [
                    'denomination' => $denomination,
                    'quantity' => $quantity,
                    'subtotal' => $denomination * $quantity,
                ])->values()->all(),
                'transactions' => TransactionResource::collection($transactions),
            ])
            ->response();
    }

    public function open(CashCountRequest $request): DailyReportResource
    {
        $shift = $this->reports->open($request->user(), $request->counts());

        return new DailyReportResource($this->reports->withLiveTotals($shift)->load('openingCounts'));
    }

    /**
     * 422 with system_balance, physical_balance and difference when the cash
     * does not match; the shift then stays open.
     */
    public function close(CashCountRequest $request): DailyReportResource
    {
        $report = $this->reports->close($request->user(), $request->counts());

        return new DailyReportResource($report->load(['openingCounts', 'closingCounts']));
    }
}
