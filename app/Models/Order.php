<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return array_fill_keys(['items_subtotal', 'item_discount', 'order_discount', 'shipping_charge', 'other_charges', 'tax_amount', 'total', 'advance', 'cod_amount'], 'decimal:2');
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    public function items(): HasMany { return $this->hasMany(OrderItem::class); }

    public function histories(): HasMany { return $this->hasMany(OrderStatusHistory::class)->orderBy('id'); }
}
