<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductImage extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'position' => 'integer', 'version' => 'integer'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function media(): BelongsTo { return $this->belongsTo(MediaFile::class, 'media_file_id'); }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'product_image_variant', 'product_image_id', 'product_variant_id');
    }
}
