<?php

/*
 * Courier provider registry. Hosts are PLATFORM configuration only: tenants can never
 * point an integration at another URL (SSRF guard, spec §5.7 / §12).
 * Steadfast values below come from the merchant API guide (base URL, headers, status codes).
 */
return [
    'steadfast' => [
        'label' => 'Steadfast',
        'base_url' => env('COURIER_STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1'),
        'timeout' => 15,
        // credential field => label shown in the UI
        'fields' => ['api_key' => 'API key', 'secret_key' => 'Secret key'],
    ],
    // 'redx' => added once the official REDX merchant API docs and test credentials are available.

    // Provider lockout protection: Steadfast refuses an address/key for 60 min after 10 failures in 5 min.
    // We stop well short of that.
    'max_failed_tests_per_5_min' => 3,
];
