<?php

namespace App\Http\Controllers\API\Teller;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerSummaryResource;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerLookupController extends Controller
{
    /**
     * Find one customer by exact account number or NIS.
     */
    public function __invoke(Request $request): CustomerSummaryResource
    {
        $identifier = $request->validate([
            'identifier' => ['required', 'string', 'max:50'],
        ])['identifier'];

        $customer = Customer::with('major')->where('account_number', $identifier)->first()
            ?? Customer::with('major')->where('nis', $identifier)->first();

        abort_unless($customer, 404, 'Nasabah tidak ditemukan.');

        return new CustomerSummaryResource($customer);
    }
}
