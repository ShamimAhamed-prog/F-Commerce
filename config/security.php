<?php

return [
    // Per-IP limits (requests/minute). Relaxed only in .env.e2e.
    'login_per_minute' => (int) env('RATE_LOGIN_PER_MINUTE', 20),
    'register_per_minute' => (int) env('RATE_REGISTER_PER_MINUTE', 10),

    // Launch policy (spec §3.3): when true, unverified users are held on the verification screen.
    'require_email_verification' => (bool) env('REQUIRE_EMAIL_VERIFICATION', false),
    'invitation_ttl_days' => 7,
];
