<?php

namespace App\Http\Controllers\API\Supervisor;

use App\Enums\CashTransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Supervisor\ReviewRequest;
use App\Http\Resources\CashTransferResource;
use App\Models\CashTransfer;
use App\Services\CashTransferService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CashTransferController extends Controller
{
    public function __construct(private CashTransferService $transfers)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(CashTransferStatus::class)],
            'teller_id' => ['nullable', 'integer'],
        ]);

        $transfers = CashTransfer::query()
            ->with(['teller', 'supervisor'])
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('teller_id'), fn ($query, $tellerId) => $query->where('teller_id', $tellerId))
            ->latest('id')
            ->paginate($this->perPage($request));

        return CashTransferResource::collection($transfers);
    }

    /**
     * One transfer plus the teller's current drawer, so the supervisor can
     * check the amount against the cash that should be there.
     */
    public function show(CashTransfer $cashTransfer): CashTransferResource
    {
        return (new CashTransferResource($cashTransfer->load(['teller', 'supervisor'])))
            ->additional(['drawer' => $this->transfers->drawer($cashTransfer->teller)]);
    }

    public function approve(ReviewRequest $request, CashTransfer $cashTransfer): CashTransferResource
    {
        $transfer = $this->transfers->approve($cashTransfer, $request->user(), $request->validated('note'));

        return new CashTransferResource($transfer->load(['teller', 'supervisor']));
    }

    public function reject(ReviewRequest $request, CashTransfer $cashTransfer): CashTransferResource
    {
        $transfer = $this->transfers->reject($cashTransfer, $request->user(), $request->validated('note'));

        return new CashTransferResource($transfer->load(['teller', 'supervisor']));
    }
}
