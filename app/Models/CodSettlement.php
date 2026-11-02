<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CodSettlement extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['received' => 'decimal:2', 'fee' => 'decimal:2', 'settled_date' => 'date'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    public function financialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class); }
}
