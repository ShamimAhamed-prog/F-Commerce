<?php

it('serves a cache-less health probe without a session', function () {
    $res = $this->get('/health')->assertOk()->assertJson(['status' => 'ok']);

    expect($res->headers->get('Cache-Control'))->toContain('no-store');
    expect($res->headers->getCookies())->toBeEmpty();
});

it('serves the static offline page', function () {
    $this->get('/offline')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
});

it('sends a strict CSP and hardening headers', function () {
    $res = $this->get('/health');

    expect($res->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'self' 'nonce-")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'");
    expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('attaches a request id', function () {
    $this->withoutVite()->get('/auth/login')->assertHeader('X-Request-ID');
});
