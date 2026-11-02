<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes that need an active workspace. */
class EnsureTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(CurrentTenant::class)->id() === null) {
            return redirect()->route('workspaces.index');
        }

        return $next($request);
    }
}
