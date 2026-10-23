<?php

namespace App\Http\Controllers\API\Teller;

use App\Http\Controllers\Controller;
use App\Http\Resources\CashTransferResource;
use App\Services\CashTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashTransferController extends Controller
{
    public function __construct(private CashTransferService $transfers)
    {
    }

    /**
     * How much cash should be in the teller's drawer right now.
     */
    public function drawer(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->transfers->drawer($request->user())]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $transfers = $request->user()->cashTransfers()
            ->with('supervisor')
            ->latest('id')
            ->paginate($this->perPage($request));

        return CashTransferResource::collection($transfers);
    }

    public function store(Request $request): CashTransferResource
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return new CashTransferResource($this->transfers->request($request->user(), $data['amount'], $data['note'] ?? null));
    }
}
