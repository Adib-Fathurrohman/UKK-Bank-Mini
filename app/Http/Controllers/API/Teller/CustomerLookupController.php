<?php

namespace App\Http\Controllers\API\Teller;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerSummaryResource;
use App\Models\Customer;
use App\Support\CustomerQr;
use Illuminate\Http\Request;

class CustomerLookupController extends Controller
{
    /**
     * Find one customer by QR code, exact account number or NIS (soal 1b).
     */
    public function __invoke(Request $request): CustomerSummaryResource
    {
        // A scanned QR code ("BANKMINI:RPL-2026-001") or a typed account number / NIS.
        $identifier = CustomerQr::identifierFrom($request->validate([
            'identifier' => ['required', 'string', 'max:60'],
        ])['identifier']);

        $customer = Customer::with('major')->where('account_number', $identifier)->first()
            ?? Customer::with('major')->where('nis', $identifier)->first();

        abort_unless($customer, 404, 'Nasabah tidak ditemukan.');

        return new CustomerSummaryResource($customer);
    }
}
