<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/** CSP + hardening headers (SEC-P1, §12). */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Per-request nonce: stamped on Vite tags and handed to Inertia for its injected <style>.
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');
        $h->set('X-Frame-Options', 'DENY');

        // The Vite dev server needs inline/HMR sockets; enforce the strict policy everywhere else.
        // A response may set its own stricter policy (e.g. sandboxed user-uploaded files); never loosen it.
        if (! app()->environment('local') && ! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'nonce-{$nonce}'",
                "img-src 'self' data: blob:",
                "font-src 'self'",
                "connect-src 'self'",
                "manifest-src 'self'",
                "worker-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
