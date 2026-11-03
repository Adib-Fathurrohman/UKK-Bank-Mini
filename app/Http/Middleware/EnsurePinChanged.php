<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A customer who logged in with a temporary PIN may only replace it (and read
 * their profile or log out) until they have. 428 rather than 403, so clients
 * that log out on 403 send the customer to the PIN change instead.
 */
class EnsurePinChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->pin_must_change) {
            return response()->json([
                'message' => 'Ganti PIN sementara Anda terlebih dahulu.',
                'code' => 'pin_change_required',
            ], 428);
        }

        return $next($request);
    }
}
