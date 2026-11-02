<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\Audit;
use App\Domain\Inventory\StockService;
use App\Domain\Tenancy\CurrentTenant;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function __construct(private StockService $stock) {}

    /** Fields persisted on the product itself. */
    private const FIELDS = [
        'name', 'sku', 'slug', 'description', 'type', 'purchase_cost', 'selling_price', 'compare_at_price',
        'tax_rate', 'barcode', 'weight', 'dimensions', 'status', 'reorder_threshold',
    ];

    /**
     * @param array $data validated input (+ optional category/brand names, variants[], opening_stock)
     */
    public function save(array $data, ?Product $product = null, bool $canSetCost = true, bool $pruneVariants = true): Product
    {
        return DB::transaction(function () use ($data, $product, $canSetCost, $pruneVariants) {
            $creating = ! $product;
            $this->guardSkus($data, $product);
            $old = $product?->only(self::FIELDS);

            $attrs = array_intersect_key($data, array_flip(self::FIELDS));
            // Non-nullable columns: a blank form field means "default" (0), never NULL.
            foreach (['purchase_cost' => 0, 'reorder_threshold' => 0, 'selling_price' => 0] as $col => $default) {
                if (array_key_exists($col, $attrs) && $attrs[$col] === null) {
                    $attrs[$col] = $default;
                }
            }
            if (! $canSetCost) {
                unset($attrs['purchase_cost']); // staff without cost permission can never write it
            }
            if (array_key_exists('category', $data)) {
                $attrs['category_id'] = $this->named(Category::class, $data['category']);
            }
            if (array_key_exists('brand', $data)) {
                $attrs['brand_id'] = $this->named(Brand::class, $data['brand']);
            }
            $attrs['updated_by'] = auth()->id();

            if ($creating) {
                $attrs['created_by'] = auth()->id();
                $product = Product::create($attrs);
            } else {
                $product->update($attrs);
            }

            $this->guardActivation($product);

            if ($product->isVariable()) {
                $this->syncVariants($product, $data['variants'] ?? [], $canSetCost, $pruneVariants);
            } elseif ($creating && ! empty($data['opening_stock'])) {
                $this->stock->record($product->id, null, 'opening', (int) $data['opening_stock'], $product->purchase_cost, 'Opening stock');
            }

            Audit::record($creating ? 'product.created' : 'product.updated', $product, $old, $product->only(self::FIELDS));

            return $product;
        });
    }

    /** Defence in depth: SKUs are unique across products AND variants, whatever the caller (form, import). */
    private function guardSkus(array $data, ?Product $product): void
    {
        $errors = [];
        if (isset($data['sku']) && SkuRegistry::taken($data['sku'], $product?->id)) {
            $errors['sku'] = "SKU {$data['sku']} is already in use.";
        }
        foreach (($data['type'] ?? $product?->type) === 'variable' ? ($data['variants'] ?? []) : [] as $i => $v) {
            $vid = ! empty($v['id']) ? (int) $v['id'] : null;
            if (SkuRegistry::taken($v['sku'], $product?->id, $vid) || $v['sku'] === ($data['sku'] ?? $product?->sku)) {
                $errors["variants.$i.sku"] = "SKU {$v['sku']} is already in use.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Tenant setting "require image before activation" (spec IMG-I6 / decision #14). */
    public function guardActivation(Product $product): void
    {
        $tenant = app(CurrentTenant::class)->get();
        if ($product->status === 'active' && ($tenant?->settings['require_image_before_activation'] ?? false)
            && ! $product->images()->where('status', 'ready')->exists()) {
            throw ValidationException::withMessages(['status' => 'Add at least one image before activating this product.']);
        }
    }

    private function syncVariants(Product $product, array $rows, bool $canSetCost, bool $prune): void
    {
        $keep = [];
        foreach (array_values($rows) as $i => $row) {
            $variant = ! empty($row['id']) ? ProductVariant::where('product_id', $product->id)->findOrFail($row['id']) : null;
            $attrs = [
                'sku' => $row['sku'],
                'barcode' => $row['barcode'] ?? null,
                'options' => $row['options'] ?? [],
                'selling_price' => $row['selling_price'] ?? null,
                'position' => $i,
            ];
            if ($canSetCost) {
                $attrs['purchase_cost'] = $row['purchase_cost'] ?? null;
            }

            if ($variant) {
                $variant->update($attrs);
            } else {
                $variant = $product->variants()->create($attrs);
                if (! empty($row['opening_stock'])) {
                    $cost = $variant->purchase_cost ?? $product->purchase_cost;
                    $this->stock->record($product->id, $variant->id, 'opening', (int) $row['opening_stock'], (string) $cost, 'Opening stock');
                }
            }
            $keep[] = $variant->id;
        }

        if (! $prune) {
            return; // imports update/add variants but never delete the ones not in the file
        }

        // Variants with stock history cannot be silently dropped (ledger FK restricts).
        foreach ($product->variants()->whereNotIn('id', $keep)->get() as $gone) {
            if (DB::table('stock_movements')->where('product_variant_id', $gone->id)->exists()) {
                throw ValidationException::withMessages(['variants' => "Variant {$gone->sku} has stock history and cannot be removed."]);
            }
            $gone->delete();
        }
    }

    private function named(string $model, ?string $name): ?int
    {
        $name = trim((string) $name);

        return $name === '' ? null : $model::firstOrCreate(['name' => $name])->id;
    }
}
