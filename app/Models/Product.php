<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToTenant, HasFactory;


    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'purchase_cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'weight' => 'decimal:3',
        ];
    }

    public function category(): BelongsTo { return $this->belongsTo(Category::class); }

    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }

    public function variants(): HasMany { return $this->hasMany(ProductVariant::class)->orderBy('position'); }

    public function images(): HasMany { return $this->hasMany(ProductImage::class)->orderBy('position'); }

    public function isVariable(): bool { return $this->type === 'variable'; }
}
