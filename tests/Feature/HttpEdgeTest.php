<?php

use Illuminate\Http\Middleware\TrustProxies;

afterEach(fn () => TrustProxies::flushState());

test('the root answers with JSON instead of a page that needs built assets', function () {
    $this->getJson('/')->assertOk()->assertJsonPath('status', 'ok');
});

test('the health endpoint is up', function () {
    $this->get('/up')->assertOk();
});

test('CORS preflight allows only the configured staff origin', function () {
    config(['cors.allowed_origins' => ['https://bank.rumput.tech']]);

    $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/staff/login', server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,idempotency-key',
    ]);

    $allowed = $preflight('https://bank.rumput.tech');
    $allowed->assertNoContent();
    expect($allowed->headers->get('Access-Control-Allow-Origin'))->toBe('https://bank.rumput.tech')
        ->and(strtolower($allowed->headers->get('Access-Control-Allow-Headers')))->toContain('authorization', 'idempotency-key')
        ->and($allowed->headers->get('Access-Control-Allow-Credentials'))->toBeNull();

    // The answer names the staff origin only, so a browser on another origin rejects it.
    expect($preflight('https://evil.example')->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});

test('responses expose the headers the browser app needs', function () {
    config(['cors.allowed_origins' => ['https://bank.rumput.tech']]);

    $response = $this->getJson('/api/staff/me', ['Origin' => 'https://bank.rumput.tech']);

    $response->assertUnauthorized();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://bank.rumput.tech')
        ->and(strtolower($response->headers->get('Access-Control-Expose-Headers')))->toContain('content-disposition', 'retry-after');
});

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

test('forwarded headers are honoured only from the configured proxy', function () {
    TrustProxies::at(['10.0.0.1']);

    $forwarded = ['HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443'];

    Route::get('/_probe', fn (Request $request) => [$request->ip(), $request->secure()]);

    $this->call('GET', '/_probe', server: [...$forwarded, 'REMOTE_ADDR' => '10.0.0.1'])->assertExactJson(['203.0.113.9', true]);
    $this->call('GET', '/_probe', server: [...$forwarded, 'REMOTE_ADDR' => '198.51.100.7'])->assertJsonPath('0', '198.51.100.7');
    $this->call('GET', '/_probe', server: ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'REMOTE_ADDR' => '10.0.0.1', 'HTTPS' => 'off'])->assertJsonPath('0', '203.0.113.9');
});
