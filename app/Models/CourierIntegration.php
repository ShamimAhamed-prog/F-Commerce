<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CourierIntegration extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    /** Secrets never leave the server: not serialised, not logged. */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'config' => 'array', 'last_tested_at' => 'datetime'];
    }

    /** Masked view for the UI. The secret key is never shown, not even partially. */
    public function masked(): array
    {
        $c = $this->credentials ?? [];
        $out = [];
        foreach (array_keys(config("couriers.{$this->provider}.fields", [])) as $field) {
            $v = (string) ($c[$field] ?? '');
            $out[$field] = $v === '' ? '' : ($field === 'secret_key' ? '••••••••' : '••••'.substr($v, -4));
        }

        return $out;
    }
}
