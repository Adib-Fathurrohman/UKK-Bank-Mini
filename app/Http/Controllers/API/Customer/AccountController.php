<?php

namespace App\Http\Controllers\API\Customer;

use App\Enums\TransactionTypes;
use App\Http\Controllers\Controller;
use App\Http\Resources\StatementLineResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The logged-in student's own account. Everything is scoped through the
 * token's owner; no customer id is ever read from the request.
 */
class AccountController extends Controller
{
    public function balance(Request $request): JsonResponse
    {
        $customer = $request->user();

        return response()->json([
            'data' => [
                'account_number' => $customer->account_number,
                'full_name' => $customer->full_name,
                'account_status' => $customer->account_status,
                'balance' => $customer->balance,
                'min_balance' => config('bank.min_balance'),
                'as_of' => now(),
            ],
        ]);
    }

    public function transactions(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::enum(TransactionTypes::class)],
        ]);

        $transactions = $request->user()->transactions()
            ->when($request->date('from'), fn ($query, $from) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($query, $to) => $query->where('created_at', '<=', $to->endOfDay()))
            ->when($request->input('type'), fn ($query, $type) => $query->where('type', $type))
            ->latest('id')
            ->paginate($this->perPage($request, 20));

        return StatementLineResource::collection($transactions);
    }

    /**
     * Another customer's transaction id simply does not exist from here (404).
     */
    public function transaction(Request $request, int $transaction): StatementLineResource
    {
        return new StatementLineResource($request->user()->transactions()->findOrFail($transaction));
    }
}
