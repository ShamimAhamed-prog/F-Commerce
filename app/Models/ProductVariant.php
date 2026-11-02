<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'selling_price' => 'decimal:2', 'purchase_cost' => 'decimal:2'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(ProductImage::class, 'product_image_variant', 'product_variant_id', 'product_image_id');
    }
}
