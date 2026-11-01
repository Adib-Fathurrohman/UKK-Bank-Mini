<?php

namespace App\Http\Requests\Teller;

/**
 * Teller asks the customer to approve a withdrawal in the mobile app.
 * Same account and amount rules as a direct withdrawal, without a PIN.
 */
class StoreWithdrawalAuthorizationRequest extends StoreTransactionRequest
{
}
