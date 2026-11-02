<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Lightweight unauthenticated probe for the client ConnectionStatus (OFF-2).
 * No session, no DB, no tenant data; never cacheable.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok'])
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
