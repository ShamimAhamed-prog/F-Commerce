<?php

namespace App\Domain\Imports;

use App\Domain\Audit\Audit;
use App\Domain\Sales\OrderService;
use App\Domain\Sales\PhoneNormalizer;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders import. Rows sharing an external_order_id form ONE order; with no id every row is its own order.
 * An order is imported all-or-nothing: a bad row invalidates its whole group. Products match by SKU only.
 */
class OrderImportProcessor
{
    private const NUMERIC = ['quantity', 'unit_price', 'discount', 'delivery_charge', 'advance_paid'];

    public function __construct(private OrderService $orders) {}

    private function normalize(array $raw, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $header => $field) {
            $v = $field && array_key_exists($header, $raw) ? trim((string) $raw[$header]) : '';
            if ($v !== '') {
                $out[$field] = in_array($field, self::NUMERIC, true) ? ProductRowNormalizer::number($v) : $v;
            }
        }

        return $out;
    }

    private function groupKey(array $n, ImportRow $row): string
    {
        return isset($n['external_order_id']) ? 'x:'.$n['external_order_id'] : 'r:'.$row->id;
    }

    public function validate(Import $import): void
    {
        $mapping = $import->mapping ?? [];
        $units = $this->unitMap();
        $existing = Order::whereNotNull('external_ref')->pluck('id', 'external_ref');
        $seen = [];
        $rows = $import->rows()->orderBy('row_number')->get();
        $bad = [];   // group keys with a problem

        foreach ($rows as $row) {
            $n = $this->normalize($row->raw_data, $mapping);
            $key = $this->groupKey($n, $row);
            $first = ! isset($seen[$key]);
            $seen[$key] = true;
            $e = [];

            if (empty($n['product_sku'])) {
                $e[] = 'Product SKU is required.';
            } elseif (! isset($units[$n['product_sku']])) {
                $e[] = "Unknown SKU {$n['product_sku']} (use a simple product SKU or a variant SKU).";
            }
            if (! isset($n['quantity']) || ! preg_match('/^[1-9]\d{0,5}$/', $n['quantity'])) {
                $e[] = 'Quantity must be a whole number of at least 1.';
            }
            foreach (['unit_price', 'discount', 'delivery_charge', 'advance_paid'] as $f) {
                if (isset($n[$f]) && ! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $n[$f])) {
                    $e[] = "{$f} must be a non-negative amount with at most 2 decimals.";
                }
            }
            if ($first) {
                if (empty($n['customer_name'])) {
                    $e[] = 'Customer name is required.';
                }
                if (empty($n['delivery_address'])) {
                    $e[] = 'Delivery address is required.';
                }
                if (! PhoneNormalizer::normalize($n['customer_phone'] ?? null)) {
                    $e[] = 'Customer phone must be a valid Bangladeshi mobile number.';
                }
                if (isset($n['external_order_id']) && isset($existing[$n['external_order_id']])) {
                    $n['_duplicate'] = true; // already imported: skipped, never created twice
                }
            }
            if ($e) {
                $bad[$key] = true;
            }
            $row->fill(['normalized_data' => $n + ['_group' => $key], 'errors' => $e ?: null, 'status' => $e ? 'invalid' : 'valid']);
            $row->save();
        }

        // All-or-nothing orders: poison the rest of a group that has any bad row.
        foreach ($rows as $row) {
            $key = $row->normalized_data['_group'];
            if (isset($bad[$key]) && $row->status === 'valid') {
                $row->update(['status' => 'invalid', 'errors' => ['Not imported: another row of the same order has errors.']]);
            }
        }

        $fresh = $import->rows()->get();
        $import->update(['options' => array_merge($import->options ?? [], [
            'validated' => true,
            'valid_rows' => $fresh->where('status', 'valid')->count(),
            'invalid_rows' => $fresh->where('status', 'invalid')->count(),
            'duplicate_products' => $fresh->filter(fn ($r) => $r->normalized_data['_duplicate'] ?? false)->count(), // duplicate orders
        ])]);
    }

    /** @return array<string,array{0:int,1:?int}> sku => [product_id, variant_id] */
    private function unitMap(): array
    {
        $map = [];
        foreach (Product::where('type', 'simple')->where('status', '!=', 'archived')->get(['id', 'sku']) as $p) {
            $map[$p->sku] = [$p->id, null];
        }
        foreach (ProductVariant::whereHas('product', fn ($q) => $q->where('status', '!=', 'archived'))->get(['id', 'product_id', 'sku']) as $v) {
            $map[$v->sku] = [$v->product_id, $v->id];
        }

        return $map;
    }

    public function run(Import $import, bool $onlyFailed = false): void
    {
        if ($user = ($import->created_by ? User::find($import->created_by) : null)) {
            Auth::setUser($user);
        }
        if ($onlyFailed) {
            $import->rows()->where('status', 'failed')->update(['status' => 'valid', 'errors' => null]);
        }
        $units = $this->unitMap();

        $groups = $import->rows()->where('status', 'valid')->orderBy('row_number')->get()->groupBy(fn ($r) => $r->normalized_data['_group']);

        foreach ($groups as $key => $rows) {
            $first = $rows->first()->normalized_data;
            try {
                if ($first['_duplicate'] ?? false) {
                    $this->mark($rows, 'skipped', ['An order with this external ID already exists.'], null);

                    continue;
                }
                $order = DB::transaction(fn () => $this->orders->create([
                    'source' => 'import',
                    'customer_name' => $first['customer_name'], 'phone' => $first['customer_phone'], 'address' => $first['delivery_address'],
                    'area' => $first['area'] ?? null, 'notes' => $first['notes'] ?? null, 'external_ref' => $first['external_order_id'] ?? null,
                    'shipping_charge' => $first['delivery_charge'] ?? 0, 'advance' => $first['advance_paid'] ?? 0,
                    'items' => $rows->map(fn ($r) => array_filter([
                        'product_id' => $units[$r->normalized_data['product_sku']][0], 'variant_id' => $units[$r->normalized_data['product_sku']][1],
                        'qty' => (int) $r->normalized_data['quantity'], 'unit_price' => $r->normalized_data['unit_price'] ?? null,
                        'discount' => $r->normalized_data['discount'] ?? 0,
                    ], fn ($v) => $v !== null))->all(),
                ], "import-{$import->id}-".md5($key)));
                $this->mark($rows, 'created', null, $order->id);
            } catch (ValidationException $e) {
                $this->mark($rows, 'failed', collect($e->errors())->flatten()->all(), null);
            } catch (\Throwable $e) {
                report($e);
                $this->mark($rows, 'failed', ['Unexpected error while importing this order.'], null);
            }
        }

        $count = fn (array $st) => $import->rows()->whereIn('status', $st)->get(['id', 'normalized_data'])
            ->map(fn ($r) => $r->normalized_data['_group'] ?? "row-{$r->id}")->unique()->count();
        $failed = $count(['failed', 'invalid']);
        $import->update([
            'created_count' => $count(['created']), 'updated_count' => 0, 'skipped_count' => $count(['skipped']), 'failed_count' => $failed,
            'status' => $failed > 0 ? 'completed_with_errors' : 'completed',
        ]);
        Audit::record('import.completed', $import, new: $import->only('created_count', 'skipped_count', 'failed_count'));
    }

    private function mark($rows, string $status, ?array $errors, ?int $orderId): void
    {
        ImportRow::whereIn('id', $rows->pluck('id'))->update([
            'status' => $status, 'errors' => $errors ? json_encode($errors) : null,
            'record_type' => $orderId ? 'order' : null, 'record_id' => $orderId,
        ]);
    }
}
