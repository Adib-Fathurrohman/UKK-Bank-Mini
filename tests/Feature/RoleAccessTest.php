<?php

use App\Enums\UserRoles;
use Illuminate\Support\Facades\Route;

/*
| The access matrix from RENCANA_PENGEMBANGAN.md, checked for every actor.
| An allowed actor may still get 404/422 (no data is sent here); what matters
| is that only the allowed actors get past authentication and authorization.
*/

$endpoints = [
    'read staff' => ['GET', '/api/staff', ['admin', 'supervisor']],
    'create staff' => ['POST', '/api/admin/staff', ['admin']],
    'block staff' => ['POST', '/api/admin/staff/999/block', ['admin']],
    'import staff' => ['POST', '/api/admin/staff/import', ['admin']],
    'read settings' => ['GET', '/api/settings', ['admin', 'supervisor', 'teller']],
    'change settings' => ['PATCH', '/api/admin/settings', ['admin']],
    'open account' => ['POST', '/api/admin/customers', ['admin']],
    'import customers' => ['POST', '/api/admin/customers/import', ['admin']],
    'close account' => ['POST', '/api/admin/customers/1/deactivate', ['admin']],
    'reset customer PIN' => ['POST', '/api/admin/customers/1/pin-reset', ['admin']],
    'read PIN reset requests' => ['GET', '/api/pin-reset-requests', ['admin', 'supervisor']],
    'set own email' => ['PUT', '/api/staff/email', ['admin', 'supervisor', 'teller']],
    'change own password' => ['POST', '/api/staff/password', ['admin', 'supervisor', 'teller']],
    'preview graduation' => ['GET', '/api/admin/graduations/preview', ['admin']],
    'run graduation' => ['POST', '/api/admin/graduations', ['admin']],
    'read graduations' => ['GET', '/api/graduations', ['admin', 'supervisor']],
    'print graduation slips' => ['GET', '/api/graduations/x/slips', ['admin', 'supervisor']],
    'approve PIN reset request' => ['POST', '/api/admin/pin-reset-requests/x/approve', ['admin']],
    'reject PIN reset request' => ['POST', '/api/admin/pin-reset-requests/x/reject', ['admin']],
    'print customer card' => ['GET', '/api/admin/customers/1/card', ['admin']],
    'read accounts' => ['GET', '/api/customers', ['admin', 'supervisor']],
    'read journal' => ['GET', '/api/audit/journals', ['admin', 'supervisor']],
    'journal summary' => ['GET', '/api/audit/journals/summary', ['admin', 'supervisor']],
    'read transaction log' => ['GET', '/api/audit/transactions', ['admin', 'supervisor']],
    'export journal' => ['GET', '/api/audit/journals/export', ['admin', 'supervisor']],
    'export audit trail' => ['GET', '/api/audit/transactions/export', ['admin', 'supervisor']],
    'annual report' => ['GET', '/api/reports/annual', ['admin', 'supervisor']],
    'customer statement' => ['GET', '/api/customers/1/statement', ['admin', 'supervisor']],
    'receipt' => ['GET', '/api/teller/transactions/1/receipt', ['teller']],
    'own statement export' => ['GET', '/api/customer/statement/export', ['customer']],
    'register push token' => ['POST', '/api/customer/push-tokens', ['customer']],
    'remove push token' => ['DELETE', '/api/customer/push-tokens', ['customer']],
    'create own PIN' => ['POST', '/api/customer/pin/setup', ['customer']],
    'see own PIN reset request' => ['GET', '/api/customer/pin-reset-request', ['customer']],
    'ask for PIN reset' => ['POST', '/api/customer/pin-reset-requests', ['customer']],
    'cancel own PIN reset' => ['POST', '/api/customer/pin-reset-requests/x/cancel', ['customer']],
    'complete own PIN reset' => ['POST', '/api/customer/pin-reset-requests/x/complete', ['customer']],
    'look up customer' => ['GET', '/api/teller/customers/lookup', ['teller']],
    'deposit' => ['POST', '/api/teller/deposits', ['teller']],
    'withdraw' => ['POST', '/api/teller/withdrawals', ['teller']],
    'request withdrawal approval' => ['POST', '/api/teller/withdrawal-authorizations', ['teller']],
    'list own withdrawal requests' => ['GET', '/api/teller/withdrawal-authorizations', ['teller']],
    'poll withdrawal request' => ['GET', '/api/teller/withdrawal-authorizations/01J0000000000000000000000X', ['teller']],
    'cancel withdrawal request' => ['POST', '/api/teller/withdrawal-authorizations/01J0000000000000000000000X/cancel', ['teller']],
    'complete approved withdrawal' => ['POST', '/api/teller/withdrawal-authorizations/01J0000000000000000000000X/complete', ['teller']],
    'list withdrawals to approve' => ['GET', '/api/customer/withdrawal-authorizations', ['customer']],
    'read withdrawal to approve' => ['GET', '/api/customer/withdrawal-authorizations/01J0000000000000000000000X', ['customer']],
    'approve withdrawal with PIN' => ['POST', '/api/customer/withdrawal-authorizations/01J0000000000000000000000X/approve', ['customer']],
    'reject withdrawal' => ['POST', '/api/customer/withdrawal-authorizations/01J0000000000000000000000X/reject', ['customer']],
    'correct transaction' => ['POST', '/api/teller/transactions/1/correct', ['teller']],
    'current shift' => ['GET', '/api/teller/shift', ['teller']],
    'open shift' => ['POST', '/api/teller/shift/open', ['teller']],
    'close shift' => ['POST', '/api/teller/shift/close', ['teller']],
    'own shift history' => ['GET', '/api/teller/daily-reports', ['teller']],
    'list reports for approval' => ['GET', '/api/supervisor/daily-reports', ['supervisor']],
    'approve report' => ['POST', '/api/supervisor/daily-reports/1/approve', ['supervisor']],
    'hold report' => ['POST', '/api/supervisor/daily-reports/1/hold', ['supervisor']],
    'reject report' => ['POST', '/api/supervisor/daily-reports/1/reject', ['supervisor']],
    'vault ledger' => ['GET', '/api/supervisor/cash-transfers', ['supervisor']],
    'own balance' => ['GET', '/api/customer/balance', ['customer']],
    'own statement' => ['GET', '/api/customer/transactions', ['customer']],
    'own card' => ['GET', '/api/customer/card', ['customer']],
    'change own PIN' => ['POST', '/api/customer/pin', ['customer']],
];

test('each endpoint is reachable only by its allowed actors', function (string $method, string $uri, array $allowed, string $actor) {
    match ($actor) {
        'guest' => null,
        'customer' => actingAsCustomer(),
        default => actingAsStaff(UserRoles::from($actor)),
    };

    $status = $this->json($method, $uri)->status();

    if ($actor === 'guest') {
        expect($status)->toBe(401);
    } elseif (in_array($actor, $allowed, true)) {
        expect($status)->not->toBeIn([401, 403]);
    } else {
        expect($status)->toBe(403);
    }
})->with($endpoints)->with(['admin', 'supervisor', 'teller', 'customer', 'guest']);

test('every API route except login, activation and staff password reset requires a Sanctum token and an actor check', function () {
    // Public on purpose: the credentials (password, or Firebase ID token with the NIS) are the proof.
    $public = [
        'api/customer/activation',
        // Staff forgot/reset password and the signed email verification link.
        'api/staff/password/forgot',
        'api/staff/password/reset',
        'api/staff/email/verify/{user}/{hash}',
    ];

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/')
            && ! str_ends_with($route->uri(), '/login')
            && ! in_array($route->uri(), $public, true));

    $unprotected = $routes->reject(function ($route) {
        $middleware = collect($route->gatherMiddleware());

        return $middleware->contains('auth:sanctum')
            && $middleware->contains(fn ($m) => $m === 'customer' || str_starts_with($m, 'staff'));
    })->map(fn ($route) => $route->uri());

    expect($routes)->not->toBeEmpty()
        ->and($unprotected->values()->all())->toBe([]);
});
