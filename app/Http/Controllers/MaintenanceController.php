<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web maintenance for hosts with no terminal (e.g. cPanel).
 *
 * Safety rules:
 *  - OFF unless MAINTENANCE_KEY is set (and at least 32 characters).
 *  - Every failure (disabled, wrong key, unknown action, throttled) is the same plain 404: nothing is revealed.
 *  - Key compared in constant time; repeated wrong keys lock the caller out.
 *  - Only the fixed commands below can run; nothing from the request is passed to Artisan.
 *  - Never outputs the key, environment values or stack traces.
 */
class MaintenanceController extends Controller
{
    /** action => commands run in order (command => arguments) */
    public const ACTIONS = [
        'clear' => ['optimize:clear' => []],                                   // cache, config, routes, views, events, compiled
        'optimize' => ['optimize:clear' => [], 'optimize' => []],              // clear, then rebuild config/route/view/event caches
        'migrate' => ['migrate' => ['--force' => true]],
        'roles' => ['fc:sync-roles' => []],
        'storage-link' => ['storage:link' => []],
        'courier-sync' => ['fc:courier-sync' => []],                           // for hosts without cron: call this URL on a schedule
    ];

    public function __invoke(Request $request, string $action): Response
    {
        $key = (string) config('security.maintenance_key');
        $ip = $request->ip();
        $failKey = "maintenance-fail:{$ip}";

        $deny = function (string $why) use ($ip, $failKey): Response {
            RateLimiter::hit($failKey, 900);
            Log::warning('maintenance route denied', ['reason' => $why, 'ip' => $ip]);

            return response('Not Found', 404, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        };

        if (strlen($key) < 32) {
            return $deny('disabled');
        }
        if (RateLimiter::tooManyAttempts($failKey, 5)) {
            return response('Not Found', 404, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        }

        $given = (string) ($request->header('X-Maintenance-Key') ?? $request->query('key', ''));
        if (! hash_equals($key, $given)) {
            return $deny('bad key');
        }
        if (! isset(self::ACTIONS[$action])) {
            return $deny('unknown action');
        }

        RateLimiter::clear($failKey);
        $lines = [];
        $ok = true;
        foreach (self::ACTIONS[$action] as $command => $args) {
            try {
                $code = Artisan::call($command, $args);
                $lines[] = ($code === 0 ? 'OK     ' : 'FAILED ').$command;
                $ok = $ok && $code === 0;
            } catch (\Throwable $e) {
                report($e);
                $lines[] = 'FAILED '.$command.' ('.class_basename($e).')'; // class name only: no messages, paths or secrets
                $ok = false;
            }
        }
        Log::info('maintenance action run', ['action' => $action, 'ok' => $ok, 'ip' => $ip]);
        try {
            Audit::record('maintenance.'.$action, null, new: ['ok' => $ok, 'ip' => $ip]);
        } catch (\Throwable) {
            // the audit table may not exist yet (e.g. before the first migrate)
        }

        return response(($ok ? "Done: {$action}\n" : "Finished with errors: {$action}\n").implode("\n", $lines)."\n", $ok ? 200 : 500, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
