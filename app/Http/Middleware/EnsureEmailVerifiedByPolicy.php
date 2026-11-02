<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerifiedByPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (config('security.require_email_verification') && $user && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
