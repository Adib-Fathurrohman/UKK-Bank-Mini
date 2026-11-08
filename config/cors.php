<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| The staff web app runs on another origin than the API and sends its Sanctum
| bearer token in the Authorization header, so no cookies are involved and
| credentials stay off. Only the origins in CORS_ALLOWED_ORIGINS
| (comma-separated, e.g. https://bank.rumput.tech) may read responses; with
| none listed, no browser origin is allowed. The native mobile app is not a
| browser and is not subject to CORS.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Requested-With'],

    // So the browser app can name downloaded PDFs and honour the rate limit.
    'exposed_headers' => ['Content-Disposition', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 600,

    'supports_credentials' => false,

];
