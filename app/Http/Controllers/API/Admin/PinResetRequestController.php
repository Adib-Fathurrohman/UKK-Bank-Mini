<?php

namespace App\Http\Controllers\API\Admin;

use App\Enums\PinResetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DecidePinResetRequest;
use App\Http\Resources\PinResetRequestResource;
use App\Models\PinResetRequest;
use App\Services\PinResetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Forgotten-PIN requests, from the administrator's side. The administrator
 * should confirm the student's identity in person before approving.
 */
class PinResetRequestController extends Controller
{
    public function __construct(private PinResetService $resets)
    {
    }

    /**
     * `?status=` filters (default: all, newest first); `?customer_id=` narrows to one account.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:'.implode(',', array_column(PinResetStatus::cases(), 'value'))]]);

        $this->resets->expireOverdue();

        $requests = PinResetRequest::query()
            ->with(['customer.major', 'decider'])
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('customer_id'), fn ($query, $id) => $query->where('customer_id', $id))
            ->latest('id')
            ->paginate($this->perPage($request));

        return PinResetRequestResource::collection($requests);
    }

    public function show(PinResetRequest $pinResetRequest): PinResetRequestResource
    {
        return $this->present($this->resets->current($pinResetRequest));
    }

    public function approve(DecidePinResetRequest $request, PinResetRequest $pinResetRequest): PinResetRequestResource
    {
        return $this->present($this->resets->approve($pinResetRequest, $request->user(), $request->validated('note')));
    }

    public function reject(DecidePinResetRequest $request, PinResetRequest $pinResetRequest): PinResetRequestResource
    {
        return $this->present($this->resets->reject($pinResetRequest, $request->user(), $request->validated('note')));
    }

    private function present(PinResetRequest $pinResetRequest): PinResetRequestResource
    {
        return new PinResetRequestResource($pinResetRequest->load(['customer.major', 'decider']));
    }
}
