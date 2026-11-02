<?php

namespace App\Http\Controllers\API\Customer;

use App\Enums\TransactionTypes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SetUpPinRequest;
use App\Http\Resources\CustomerCardResource;
use App\Http\Resources\StatementLineResource;
use App\Services\CustomerAuthService;
use App\Services\PdfReportService;
use App\Services\PinService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The logged-in student's own account. Everything is scoped through the
 * token's owner; no customer id is ever read from the request.
 */
class AccountController extends Controller
{
    public function balance(Request $request, SettingsService $settings): JsonResponse
    {
        $customer = $request->user();

        return response()->json([
            'data' => [
                'account_number' => $customer->account_number,
                'full_name' => $customer->full_name,
                'account_status' => $customer->account_status,
                'balance' => $customer->balance,
                'min_balance' => $settings->minBalance(),
                'as_of' => now(),
            ],
        ]);
    }

    /**
     * The digital card with the QR code the teller scans at the counter.
     */
    public function card(Request $request): CustomerCardResource
    {
        return new CustomerCardResource($request->user()->load('major'));
    }

    /**
     * Create the PIN: right after activation, or after an administrator reset.
     * Needs a fresh Firebase ID token of the bound account, so an unattended
     * phone with a running session is not enough to set a PIN.
     */
    public function setUpPin(SetUpPinRequest $request, CustomerAuthService $auth, PinService $pins): JsonResponse
    {
        $auth->verifyReauthentication($request->user(), $request->validated('id_token'));
        $pins->setUp($request->user(), $request->validated('pin'));

        return response()->json(['message' => 'PIN berhasil dibuat.'], 201);
    }

    /**
     * Change the PIN. Wrong current PINs count towards the same lock as withdrawals.
     */
    public function changePin(Request $request, PinService $pins): JsonResponse
    {
        $data = $request->validate([
            'current_pin' => ['required', 'string', 'digits:6'],
            'new_pin' => ['required', 'string', 'digits:6', 'different:current_pin', 'confirmed'],
        ]);

        $pins->change($request->user(), $data['current_pin'], $data['new_pin']);

        return response()->json(['message' => 'PIN berhasil diubah.']);
    }

    /**
     * The customer's own statement as PDF ("laporan mutasi mandiri").
     */
    public function statement(Request $request, PdfReportService $pdf): Response
    {
        $range = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return $pdf->statement(
            $request->user(),
            isset($range['from']) ? Carbon::parse($range['from'])->startOfDay() : null,
            isset($range['to']) ? Carbon::parse($range['to'])->endOfDay() : null,
        );
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
