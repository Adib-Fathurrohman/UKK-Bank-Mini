<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows only active staff, optionally limited to specific roles.
 *
 * Usage: 'staff' (any role) or 'staff:admin,supervisor'.
 */
class EnsureStaffRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403, 'Fitur ini khusus untuk staf bank.');
        }

        if (! $user->is_active) {
            abort(403, 'Akun staf ini sudah dinonaktifkan.');
        }

        if ($roles !== [] && ! in_array($user->role->value, $roles, true)) {
            abort(403, 'Peran Anda tidak memiliki akses ke fitur ini.');
        }

        return $next($request);
    }
}
