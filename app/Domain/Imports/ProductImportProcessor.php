<?php

namespace App\Domain\Imports;

use App\Domain\Audit\Audit;
use App\Domain\Catalog\ProductService;
use App\Domain\Tenancy\CurrentTenant;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validate and import product rows (data only). Makes no network calls.
 * Rows sharing a parent SKU form one product; each row with a variant_sku is a variant.
 */
class ProductImportProcessor
{
    public function __construct(private ProductService $products) {}

    // -------------------------------------------------------------- validate

    public function validate(Import $import): void
    {
        $mapping = $import->mapping ?? [];
        $policy = $import->options['duplicate_policy'] ?? 'skip';
        $seenParent = [];   // sku => row_number (first row)
        $seenSku = [];      // every sku/variant sku in this file => row_number
        $groupType = [];    // sku => simple|variable
        $groupRows = [];    // sku => count
        $valid = $invalid = $duplicates = 0;

        $existingProducts = Product::pluck('id', 'sku');
        $existingVariants = ProductVariant::pluck('product_id', 'sku');

        $import->rows()->orderBy('row_number')->chunkById(500, function ($rows) use (&$seenParent, &$seenSku, &$groupType, &$groupRows, &$valid, &$invalid, &$duplicates, $mapping, $policy, $existingProducts, $existingVariants) {
            foreach ($rows as $row) {
                $n = ProductRowNormalizer::normalize($row->raw_data, $mapping);
                $errors = [];
                $sku = $n['sku'] ?? null;

                if (! $sku) {
                    $errors[] = 'SKU is required.';
                } elseif (! preg_match('/^[A-Za-z0-9._\-\/]+$/', $sku) || strlen($sku) > 100) {
                    $errors[] = 'SKU may only contain letters, numbers and . _ - /';
                }

                $isFirst = $sku && ! isset($seenParent[$sku]);
                $hasVariant = isset($n['variant_sku']) || $this->options($n) !== [];

                if ($sku && $isFirst) {
                    $seenParent[$sku] = $row->row_number;
                    $groupRows[$sku] = 0;
                    $type = $n['product_type'] ?? ($hasVariant ? 'variable' : 'simple');
                    if (! in_array($type, ['simple', 'variable'], true)) {
                        $errors[] = 'Type must be simple or variable.';
                        $type = $hasVariant ? 'variable' : 'simple';
                    }
                    if ($hasVariant) {
                        $type = 'variable';
                    }
                    $groupType[$sku] = $type;
                    $errors = array_merge($errors, $this->productErrors($n));

                    if (isset($existingProducts[$sku])) {
                        $n['_duplicate'] = true;
                        $duplicates++;
                    }
                    if (isset($seenSku[$sku]) || isset($existingVariants[$sku])) {
                        $errors[] = 'SKU is already used by a variant.';
                    }
                    $seenSku[$sku] = $row->row_number;
                } elseif ($sku && isset($seenParent[$sku])) {
                    if (($groupType[$sku] ?? null) !== 'variable') {
                        $errors[] = "Duplicate SKU in file (first used on row {$seenParent[$sku]}).";
                    }
                }
                if ($sku) {
                    $groupRows[$sku] = ($groupRows[$sku] ?? 0) + 1;
                }

                if ($sku && ($groupType[$sku] ?? null) === 'variable') {
                    $errors = array_merge($errors, $this->variantErrors($n, $sku, $row->row_number, $seenSku, $existingProducts, $existingVariants, $policy));
                }
                foreach (['opening_stock', 'variant_stock', 'reorder_threshold'] as $f) {
                    if (isset($n[$f]) && ! preg_match('/^\d{1,8}$/', $n[$f])) {
                        $errors[] = "{$f} must be a whole number.";
                    }
                }

                $row->update([
                    'normalized_data' => $n,
                    'errors' => $errors ?: null,
                    'status' => $errors ? 'invalid' : 'valid',
                ]);
                $errors ? $invalid++ : $valid++;
            }
        });

        $import->update(['options' => array_merge($import->options ?? [], [
            'validated' => true, 'valid_rows' => $valid, 'invalid_rows' => $invalid, 'duplicate_products' => $duplicates,
        ])]);
    }

    private function productErrors(array $n): array
    {
        $e = [];
        if (empty($n['name'])) {
            $e[] = 'Product name is required.';
        } elseif (mb_strlen($n['name']) > 200) {
            $e[] = 'Product name is too long.';
        }
        if (! isset($n['selling_price'])) {
            $e[] = 'Selling price is required.';
        }
        foreach (['purchase_cost', 'selling_price', 'compare_at_price', 'variant_price', 'variant_cost'] as $f) {
            if (isset($n[$f]) && ! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $n[$f])) {
                $e[] = "{$f} must be a non-negative amount with at most 2 decimals.";
            }
        }
        if (isset($n['tax_rate']) && (! is_numeric($n['tax_rate']) || $n['tax_rate'] < 0 || $n['tax_rate'] > 100)) {
            $e[] = 'tax_rate must be between 0 and 100.';
        }
        if (isset($n['weight']) && (! is_numeric($n['weight']) || $n['weight'] < 0)) {
            $e[] = 'weight must be a non-negative number.';
        }
        if (isset($n['status']) && ! in_array($n['status'], ['draft', 'active', 'archived'], true)) {
            $e[] = 'Status must be draft, active or archived.';
        }

        return $e;
    }

    private function variantErrors(array $n, string $parent, int $row, array &$seenSku, $existingProducts, $existingVariants, string $policy): array
    {
        $e = [];
        $vsku = $n['variant_sku'] ?? null;
        if (! $vsku) {
            return ['Variant SKU is required for variable products.'];
        }
        if (! preg_match('/^[A-Za-z0-9._\-\/]+$/', $vsku)) {
            $e[] = 'Variant SKU has invalid characters.';
        }
        if ($this->options($n) === []) {
            $e[] = 'At least one option name and value is required for a variant.';
        }
        if (isset($seenSku[$vsku])) {
            $e[] = "Variant SKU is already used on row {$seenSku[$vsku]}.";
        }
        $seenSku[$vsku] = $row;
        if (isset($existingProducts[$vsku])) {
            $e[] = 'Variant SKU is already used by a product.';
        }
        // A variant SKU owned by another product is only a hard conflict for a NEW product; for an existing
        // parent the duplicate policy decides (skip / update / new SKU) and writes re-check ownership.
        if (isset($existingVariants[$vsku]) && ! isset($existingProducts[$parent])) {
            $e[] = 'Variant SKU is already used by another product.';
        }

        return $e;
    }

    /** @return array<string,string> option name => value */
    public function options(array $n): array
    {
        $o = [];
        foreach ([1, 2, 3] as $i) {
            if (isset($n["option{$i}_name"], $n["option{$i}_value"])) {
                $o[$n["option{$i}_name"]] = $n["option{$i}_value"];
            }
        }

        return $o;
    }

    // ------------------------------------------------------------------- run

    public function run(Import $import, bool $onlyFailed = false): void
    {
        $user = $import->created_by ? User::find($import->created_by) : null;
        if ($user) {
            Auth::setUser($user);
        }
        $tenant = app(CurrentTenant::class)->get();
        $canSetCost = $user && $user->hasTenantPermission('products.costs.view', $tenant->id);
        $policy = $import->options['duplicate_policy'] ?? 'skip';
        $draftOnly = (bool) ($tenant->settings['require_image_before_activation'] ?? false);

        if ($onlyFailed) {
            $import->rows()->where('status', 'failed')->update(['status' => 'valid', 'errors' => null]);
        }

        $groups = $import->rows()->whereIn('status', ['valid'])->orderBy('row_number')->get()
            ->groupBy(fn (ImportRow $r) => $r->normalized_data['sku']);

        foreach ($groups as $sku => $rows) {
            try {
                [$status, $note, $productId] = DB::transaction(fn () => $this->importGroup($sku, $rows, $policy, $canSetCost, $draftOnly));
                $this->mark($rows, $status, $note ? [$note] : null, $productId);
            } catch (ValidationException $e) {
                $this->mark($rows, 'failed', collect($e->errors())->flatten()->all());
            } catch (\Throwable $e) {
                report($e);
                $this->mark($rows, 'failed', ['Unexpected error while importing this row.']);
            }
        }

        $failed = $this->groupCount($import, ['failed', 'invalid']);
        $import->update([
            'created_count' => $this->groupCount($import, ['created']),
            'updated_count' => $this->groupCount($import, ['updated']),
            'skipped_count' => $this->groupCount($import, ['skipped']),
            'failed_count' => $failed,
            'status' => $failed > 0 ? 'completed_with_errors' : 'completed',
        ]);
        Audit::record('import.completed', $import, new: $import->only('created_count', 'updated_count', 'skipped_count', 'failed_count'));
    }

    /** Counts products (distinct parent SKU groups), not sheet rows. Rows without a SKU count individually. */
    private function groupCount(Import $import, array $statuses): int
    {
        return $import->rows()->whereIn('status', $statuses)->get(['id', 'normalized_data'])
            ->map(fn ($r) => $r->normalized_data['sku'] ?? "row-{$r->id}")->unique()->count();
    }

    private function mark($rows, string $status, ?array $errors, ?int $productId = null): void
    {
        ImportRow::whereIn('id', $rows->pluck('id'))->update([
            'status' => $status,
            'errors' => $errors ? json_encode($errors) : null,
            'record_type' => $productId ? 'product' : null,
            'record_id' => $productId,
        ]);
    }

    /** @return array{0:string,1:?string,2:?int} status, note, product id */
    private function importGroup(string $sku, $rows, string $policy, bool $canSetCost, bool $draftOnly): array
    {
        $first = $rows->first();
        $n = $first->normalized_data;
        $existing = Product::where('sku', $sku)->first();

        if ($existing && $policy === 'skip') {
            return ['skipped', 'SKU already exists; skipped by duplicate policy.', $existing->id];
        }

        $suffix = null;
        if ($existing && $policy === 'create_new_sku') {
            $suffix = $this->freeSuffix($sku, $rows);
            $existing = null;
        }
        $with = fn (string $s) => $suffix ? "{$s}-{$suffix}" : $s;

        $data = [
            'sku' => $with($sku),
            'type' => count(array_filter($rows->pluck('normalized_data.variant_sku')->all())) ? 'variable' : ($n['product_type'] ?? 'simple'),
        ];
        foreach (['name', 'description', 'category', 'brand', 'purchase_cost', 'selling_price', 'compare_at_price', 'tax_rate', 'barcode', 'weight', 'dimensions', 'reorder_threshold'] as $f) {
            if (isset($n[$f])) {
                $data[$f] = $n[$f];
            }
        }
        // IMG-I6: with "require image before activation" on, imports land as draft.
        $data['status'] = $draftOnly ? 'draft' : ($n['status'] ?? ($existing->status ?? 'draft'));
        if (! $existing) {
            $data['opening_stock'] = $n['opening_stock'] ?? null;
        }
        $data['selling_price'] ??= $existing?->selling_price ?? 0;
        $data['name'] ??= $existing?->name;

        if ($data['type'] === 'variable') {
            $data['variants'] = [];
            foreach ($rows as $r) {
                $v = $r->normalized_data;
                $vsku = $with($v['variant_sku']);
                $current = ProductVariant::where('sku', $vsku)->first();
                if ($current && (! $existing || $current->product_id !== $existing->id)) {
                    throw ValidationException::withMessages(['variant' => "Variant SKU {$vsku} belongs to another product."]);
                }
                $data['variants'][] = array_filter([
                    'id' => $current?->id,
                    'sku' => $vsku,
                    'barcode' => $v['variant_barcode'] ?? null,
                    'options' => $this->options($v),
                    'selling_price' => $v['variant_price'] ?? null,
                    'purchase_cost' => $v['variant_cost'] ?? null,
                    'opening_stock' => $v['variant_stock'] ?? null,
                ], fn ($x) => $x !== null);
            }
        }

        $product = $this->products->save($data, $existing, $canSetCost, pruneVariants: false);

        return [$existing ? 'updated' : 'created', $suffix ? "Created as new SKU {$data['sku']}." : null, $product->id];
    }

    private function freeSuffix(string $sku, $rows): int
    {
        for ($i = 2; $i < 1000; $i++) {
            $taken = \App\Domain\Catalog\SkuRegistry::taken("{$sku}-{$i}");
            foreach ($rows as $r) {
                $v = $r->normalized_data['variant_sku'] ?? null;
                $taken = $taken || ($v && \App\Domain\Catalog\SkuRegistry::taken("{$v}-{$i}"));
            }
            if (! $taken) {
                return $i;
            }
        }
        throw ValidationException::withMessages(['sku' => 'Could not find a free SKU.']);
    }
}
