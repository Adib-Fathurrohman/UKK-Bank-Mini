<?php

namespace App\Http\Controllers\API\Supervisor;

use App\Http\Controllers\Controller;
use App\Http\Resources\CashTransferResource;
use App\Models\CashTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only ledger of cash moved from teller drawers to the main vault.
 * Transfers are created by approving a daily report.
 */
class CashTransferController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'teller_id' => ['nullable', 'integer'],
        ]);

        $query = CashTransfer::query()
            ->when($request->date('from'), fn ($query, $from) => $query->where('transferred_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($query, $to) => $query->where('transferred_at', '<=', $to->endOfDay()))
            ->when($request->input('teller_id'), fn ($query, $tellerId) => $query->whereHas('dailyReport', fn ($query) => $query->where('teller_id', $tellerId)));

        $total = (int) (clone $query)->sum('amount');

        return CashTransferResource::collection(
            $query->with(['dailyReport.teller', 'approver'])->latest('id')->paginate($this->perPage($request))
        )->additional(['total_amount' => $total]);
    }

    public function show(CashTransfer $cashTransfer): CashTransferResource
    {
        return new CashTransferResource($cashTransfer->load(['dailyReport.teller', 'approver']));
    }
}
