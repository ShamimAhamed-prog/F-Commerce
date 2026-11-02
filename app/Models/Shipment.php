<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use BelongsToTenant;

    /** Provider statuses after which nothing more will change (guide: wait for these, not *_approval_pending). */
    public const FINAL = ['delivered', 'partial_delivered', 'cancelled'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['cod_amount' => 'decimal:2', 'booked_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    public function integration(): BelongsTo { return $this->belongsTo(CourierIntegration::class, 'courier_integration_id'); }

    public function events(): HasMany { return $this->hasMany(ShipmentEvent::class)->orderBy('id'); }

    /** A booking that still blocks a new one (in flight, live at the provider, or unresolved). */
    public function isActive(): bool
    {
        if ($this->state === 'failed') {
            return false;
        }

        return ! ($this->state === 'booked' && $this->provider_status === 'cancelled');
    }
}
