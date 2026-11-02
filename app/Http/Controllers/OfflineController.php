<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** /offline: the static, tenant-agnostic fallback page (§13). */
class OfflineController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        return response()->file(public_path('offline.html'), [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
