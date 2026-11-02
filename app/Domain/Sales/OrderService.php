<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\AccountingException;
use App\Domain\Accounting\Poster;
use App\Domain\Audit\Audit;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\StockService;
use App\Domain\Tenancy\CurrentTenant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /** Explicit transition rules (spec §5.5). */
    public const TRANSITIONS = [
        'draft' => ['pending_confirmation', 'cancelled'],
        'pending_confirmation' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['ready_to_ship', 'cancelled'],
        'ready_to_ship' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'delivery_failed'],
        'delivered' => ['return_requested'],
        'delivery_failed' => ['return_requested'],
        'return_requested' => ['returned'],
    ];

    public function __construct(private StockService $stock) {}

    /**
     * @param array $d customer_name, phone, address, area?, items[{product_id,variant_id?,qty,unit_price?,discount?}],
     *                 order_discount?, shipping_charge?, other_charges?, tax_amount?, advance?, notes?, status?, source?, external_ref?
     * Replaying the same idempotency key returns the ORIGINAL order (OFF-8): no duplicates.
     */
    public function create(array $d, ?string $idempotencyKey = null): Order
    {
        if ($idempotencyKey && ($existing = Order::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->build($d, $idempotencyKey));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key / external ref won the race.
            if ($idempotencyKey && ($existing = Order::where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            throw $e;
        }
    }

    private function build(array $d, ?string $key): Order
    {
        $tenantId = app(CurrentTenant::class)->id();
        $phone = PhoneNormalizer::normalize($d['phone']) ?? throw ValidationException::withMessages(['phone' => 'Enter a valid Bangladeshi mobile number.']);

        $lines = [];
        $snap = [];
        foreach (array_values($d['items']) as $i => $it) {
            [$product, $variant] = $this->resolve($it, $i);
            $price = $it['unit_price'] ?? ($variant?->selling_price ?? $product->selling_price);
            $lines[$i] = ['qty' => (int) $it['qty'], 'unit_price' => number_format((float) $price, 2, '.', ''), 'discount' => number_format((float) ($it['discount'] ?? 0), 2, '.', '')];
            $snap[$i] = [$product, $variant];
        }

        try {
            $m = fn ($k) => number_format((float) ($d[$k] ?? 0), 2, '.', '');
            $t = OrderTotals::compute($lines, $m('order_discount'), $m('shipping_charge'), $m('other_charges'), $m('tax_amount'), $m('advance'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        $customer = $this->customer($d, $phone);

        Tenant::whereKey($tenantId)->lockForUpdate()->first(); // serialise per-tenant numbering
        $seq = (int) Order::max('seq') + 1;

        $order = Order::create([
            'seq' => $seq, 'order_number' => 'ORD-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'source' => $d['source'] ?? 'manual', 'customer_id' => $customer->id, 'customer_name' => $d['customer_name'],
            'phone' => $d['phone'], 'address' => $d['address'], 'area' => $d['area'] ?? null,
            'items_subtotal' => $t['items_subtotal'], 'item_discount' => $t['item_discount'], 'order_discount' => $m('order_discount'),
            'shipping_charge' => $m('shipping_charge'), 'other_charges' => $m('other_charges'), 'tax_amount' => $m('tax_amount'),
            'total' => $t['total'], 'advance' => $m('advance'), 'cod_amount' => $t['cod_amount'],
            'status' => ($d['status'] ?? 'pending_confirmation') === 'draft' ? 'draft' : 'pending_confirmation',
            'notes' => $d['notes'] ?? null, 'external_ref' => $d['external_ref'] ?? null, 'idempotency_key' => $key,
            'created_by' => auth()->id(),
        ]);

        foreach ($lines as $i => $l) {
            [$product, $variant] = $snap[$i];
            $order->items()->create([
                'product_id' => $product->id, 'product_variant_id' => $variant?->id, 'sku' => $variant?->sku ?? $product->sku,
                'name' => $product->name.($variant ? ' — '.implode(' / ', $variant->options) : ''),
                'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'discount' => $l['discount'], 'line_total' => $t['line_totals'][$i],
            ]);
        }

        OrderStatusHistory::create(['order_id' => $order->id, 'old_status' => null, 'new_status' => $order->status, 'actor_id' => auth()->id(), 'note' => 'Order created']);
        Audit::record('order.created', $order, new: ['number' => $order->order_number, 'total' => $order->total]);

        return $order;
    }

    /** Find-or-create by normalised phone (the original number is kept on the customer). */
    private function customer(array $d, string $phone): Customer
    {
        $c = Customer::firstOrCreate(['phone_normalized' => $phone], ['name' => $d['customer_name'], 'phone' => $d['phone']]);
        $c->fill(['address' => $d['address'], 'area' => $d['area'] ?? $c->area])->save();

        return $c;
    }

    /** @return array{0:Product,1:?ProductVariant} tenant-scoped; variable products need a variant */
    public function resolve(array $it, int $i): array
    {
        $product = Product::where('status', '!=', 'archived')->find($it['product_id'] ?? 0)
            ?? throw ValidationException::withMessages(["items.$i" => 'Unknown product.']);
        $variant = null;
        if ($product->isVariable()) {
            $variant = $product->variants()->find($it['variant_id'] ?? 0)
                ?? throw ValidationException::withMessages(["items.$i" => "Choose a variant for {$product->name}."]);
        } elseif (! empty($it['variant_id'])) {
            throw ValidationException::withMessages(["items.$i" => 'This product has no variants.']);
        }

        return [$product, $variant];
    }

    /** Move an order along its lifecycle, keeping stock and history consistent. */
    public function transition(Order $order, string $to, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $to, $note) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $from = $order->status;
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "An order that is {$from} cannot become {$to}."]);
            }

            try {
                $this->moveStock($order, $from, $to);
                if ($to === 'shipped') {
                    app(Poster::class)->orderShipped($order);
                } elseif ($to === 'returned') {
                    app(Poster::class)->orderReturned($order);
                }
            } catch (InsufficientStock|AccountingException $e) {
                throw ValidationException::withMessages(['status' => $e->getMessage()]);
            }

            $order->update(['status' => $to]);
            OrderStatusHistory::create(['order_id' => $order->id, 'old_status' => $from, 'new_status' => $to, 'actor_id' => auth()->id(), 'note' => $note]);
            Audit::record('order.status_changed', $order, ['status' => $from], ['status' => $to]);

            return $order;
        });
    }

    /** Reserve on confirmation, deduct on dispatch, release on cancellation (decision #3). */
    private function moveStock(Order $order, string $from, string $to): void
    {
        $items = $order->items()->get();
        $each = fn (string $type, int $sign) => $items->each(fn ($i) => $this->stock->record(
            $i->product_id, $i->product_variant_id, $type, $sign * $i->qty, null, "Order {$order->order_number}", $order));

        if ($to === 'confirmed' && $order->stock_state === 'none') {
            try {
                $each('reservation', 1);
            } catch (InsufficientStock $e) {
                throw new InsufficientStock('Not enough stock to confirm this order: '.$e->getMessage());
            }
            $order->stock_state = 'reserved';
        } elseif ($to === 'shipped' && $order->stock_state === 'reserved') {
            $each('sale', -1); // consumes the reservation
            $order->stock_state = 'deducted';
        } elseif ($to === 'cancelled' && $order->stock_state === 'reserved') {
            $each('release', -1);
            $order->stock_state = 'none';
        }
        $order->save();
    }
}
