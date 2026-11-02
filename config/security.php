<?php

return [
    // Per-IP limits (requests/minute). Relaxed only in .env.e2e.
    'login_per_minute' => (int) env('RATE_LOGIN_PER_MINUTE', 20),
    'register_per_minute' => (int) env('RATE_REGISTER_PER_MINUTE', 10),

    // Launch policy (spec §3.3): when true, unverified users are held on the verification screen.
    'require_email_verification' => (bool) env('REQUIRE_EMAIL_VERIFICATION', false),
    'invitation_ttl_days' => 7,

    // Web maintenance tools for hosts without terminal access (cPanel). DISABLED unless a long random key is set.
    'maintenance_key' => env('MAINTENANCE_KEY'),
];
