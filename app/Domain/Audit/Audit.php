<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\CurrentTenant;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /** Keys never written to the audit trail. */
    private const REDACT = ['password', 'password_confirmation', 'remember_token', 'credentials', 'token', 'secret'];

    public static function record(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        array $meta = [],
        ?int $tenantId = null,
    ): AuditLog {
        $request = app()->bound('request') ? request() : null;

        return AuditLog::create([
            'tenant_id' => $tenantId ?? app(CurrentTenant::class)->id(),
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'old_values' => $old ? self::redact($old) : null,
            'new_values' => $new ? self::redact($new) : null,
            'meta' => $meta ?: null,
            'request_id' => $request?->attributes->get('request_id'),
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    private static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
