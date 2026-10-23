<?php

namespace App\Http\Controllers\API\Audit;

use App\Enums\TransactionTypes;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Read-only transaction log for administrators and supervisors.
 */
class TransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::enum(TransactionTypes::class)],
            'teller_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
        ]);

        $transactions = Transaction::query()
            ->with(['customer', 'teller'])
            ->when($request->date('from'), fn ($query, $from) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($query, $to) => $query->where('created_at', '<=', $to->endOfDay()))
            ->when($request->input('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->input('teller_id'), fn ($query, $tellerId) => $query->where('user_id', $tellerId))
            ->when($request->input('customer_id'), fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->latest('id')
            ->paginate($this->perPage($request));

        return TransactionResource::collection($transactions);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource($transaction->load(['customer', 'teller', 'accountingJournals']));
    }
}
