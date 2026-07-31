<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OrcaRail API Credentials
    |--------------------------------------------------------------------------
    |
    | Your OrcaRail API key and secret are used for server-to-server requests.
    | Create them in the OrcaRail dashboard under API Keys.
    |
    */

    'api_key' => env('ORCARAIL_API_KEY'),
    'api_secret' => env('ORCARAIL_API_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    */

    'base_url' => env('ORCARAIL_BASE_URL', 'https://api.orcarail.com/api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Hosted Pay URL
    |--------------------------------------------------------------------------
    |
    | Used as a fallback when building checkout redirects from a payment link
    | slug. Prefer the `link` / `pay_url` / redirect URL returned by the API.
    |
    */

    'pay_url' => env('ORCARAIL_PAY_URL', 'https://pay.orcarail.com'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */

    'webhook' => [
        'secret' => env('ORCARAIL_WEBHOOK_SECRET'),
        'path' => env('ORCARAIL_WEBHOOK_PATH', 'orcarail/webhook'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    */

    'currency' => env('ORCARAIL_CURRENCY', 'usd'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeouts (milliseconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('ORCARAIL_TIMEOUT', 30000),
    'connect_timeout' => (int) env('ORCARAIL_CONNECT_TIMEOUT', 10000),

];
