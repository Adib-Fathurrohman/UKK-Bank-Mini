<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows only customers (students) whose account has not been closed.
 */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user();

        if (! $customer instanceof Customer) {
            abort(403, 'Fitur ini khusus untuk nasabah.');
        }

        if ($customer->account_status === AccountStatus::INACTIVE) {
            abort(403, 'Rekening ini sudah tidak aktif.');
        }

        return $next($request);
    }
}
