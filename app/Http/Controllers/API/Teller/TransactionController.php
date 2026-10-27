<?php

namespace App\Http\Controllers\API\Teller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teller\StoreTransactionRequest;
use App\Http\Requests\Teller\WithdrawalRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Customer;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class TransactionController extends Controller
{
    public function __construct(private TransactionService $transactions)
    {
    }

    /**
     * The logged-in teller's own transactions for one day (default: today).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['date' => ['nullable', 'date']]);

        $date = Carbon::parse($request->input('date', 'today'));

        $transactions = $request->user()->transactions()
            ->with('customer')
            ->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->latest('id')
            ->paginate($this->perPage($request, 50));

        return TransactionResource::collection($transactions);
    }

    public function deposit(StoreTransactionRequest $request): TransactionResource
    {
        return new TransactionResource($this->transactions->deposit(
            $this->customerFrom($request),
            $request->integer('amount'),
            $request->user(),
        ));
    }

    public function withdraw(WithdrawalRequest $request): TransactionResource
    {
        return new TransactionResource($this->transactions->withdraw(
            $this->customerFrom($request),
            $request->integer('amount'),
            $request->user(),
            $request->validated('pin'),
        ));
    }

    private function customerFrom(StoreTransactionRequest $request): Customer
    {
        return Customer::where('account_number', $request->validated('account_number'))->firstOrFail();
    }
}
