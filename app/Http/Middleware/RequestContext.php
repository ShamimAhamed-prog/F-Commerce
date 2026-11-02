<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Request ID + structured log context (NFR-10). Runs after tenant resolution. */
class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->attributes->get('request_id')
            ?? $this->sanitize($request->header('X-Request-ID'))
            ?? (string) Str::uuid();
        $request->attributes->set('request_id', $id);

        Log::withContext([
            'request_id' => $id,
            'tenant_id' => app(CurrentTenant::class)->id(),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        return $response;
    }

    private function sanitize(?string $value): ?string
    {
        return $value && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $value) ? $value : null;
    }
}
