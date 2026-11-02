<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest('id');
    }
}
