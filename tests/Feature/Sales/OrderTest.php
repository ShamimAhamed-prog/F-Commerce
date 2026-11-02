<?php

use App\Domain\Inventory\StockService;
use App\Domain\Sales\OrderTotals;
use App\Domain\Sales\PhoneNormalizer;
use App\Models\Customer;
use App\Models\Import;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    [$this->user, $this->tenant] = memberOf();
    asTenant($this->tenant);
    $this->as = fn ($u = null) => $this->actingAs($u ?? $this->user)->withSession(['tenant_id' => $this->tenant->id]);
    $this->p = Product::factory()->create(['sku' => 'MUG', 'name' => 'Mug', 'selling_price' => '2000.00', 'purchase_cost' => '900']);
    app(StockService::class)->record($this->p->id, null, 'opening', 10, '900');
    $this->payload = fn (array $o = []) => array_merge([
        'idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Rahim', 'phone' => '+880 1712-345678', 'address' => 'Dhanmondi', 'area' => 'Dhaka',
        'shipping_charge' => '80', 'advance' => '100', 'items' => [['product_id' => $this->p->id, 'qty' => 1]],
    ], $o);
    $this->place = fn (array $o = []) => ($this->as)()->post('/app/orders', ($this->payload)($o));
    $this->stock = fn () => StockBalance::where('product_id', $this->p->id)->first();
});

it('computes totals and COD per the spec formula (spec example: 2080 / 1980)', function () {
    ($this->place)()->assertSessionHasNoErrors();
    $o = Order::first();
    expect((string) $o->total)->toBe('2080.00')->and((string) $o->cod_amount)->toBe('1980.00')->and($o->order_number)->toBe('ORD-000001')
        ->and($o->status)->toBe('pending_confirmation');

    $t = OrderTotals::compute([['qty' => 3, 'unit_price' => '100.50', 'discount' => '10.00']], '5.00', '0.00', '20.00', '2.25', '0.00');
    expect($t['total'])->toBe('308.75');
    expect(fn () => OrderTotals::compute([['qty' => 1, 'unit_price' => '10.00', 'discount' => '0.00']], '0.00', '0.00', '0.00', '0.00', '50.00'))->toThrow(InvalidArgumentException::class);
});

it('snapshots price and name so later product edits never rewrite history', function () {
    ($this->place)();
    $this->p->update(['selling_price' => '9999.00', 'name' => 'Renamed']);
    $i = Order::first()->items->first();
    expect((string) $i->unit_price)->toBe('2000.00')->and($i->name)->toBe('Mug');
});

it('is idempotent: replaying the same key returns the original order, no duplicate', function () {
    $key = (string) Str::uuid();
    ($this->place)(['idempotency_key' => $key])->assertRedirect();
    ($this->place)(['idempotency_key' => $key])->assertRedirect();
    expect(Order::count())->toBe(1);
    ($this->place)()->assertRedirect(); // new intent, new order
    expect(Order::count())->toBe(2)->and(Order::orderBy('seq')->pluck('seq')->all())->toBe([1, 2]);
});

it('matches customers by normalised phone and keeps the original number', function () {
    ($this->place)();
    ($this->place)(['phone' => '01712345678', 'customer_name' => 'Rahim U']);
    expect(Customer::count())->toBe(1)->and(Customer::first()->phone_normalized)->toBe('01712345678')->and(Customer::first()->phone)->toBe('+880 1712-345678');
    ($this->place)(['phone' => '12345'])->assertSessionHasErrors('phone');
    expect(PhoneNormalizer::normalize('০১৭১২৩৪৫৬৭৮'))->toBe('01712345678');
});

it('rejects bad items: unknown product, missing variant, excessive discount, advance over total', function () {
    ($this->place)(['items' => [['product_id' => 99999, 'qty' => 1]]])->assertSessionHasErrors('items.0');
    $v = Product::factory()->create(['type' => 'variable']);
    $v->variants()->create(['sku' => 'V1', 'options' => ['S' => 'M']]);
    ($this->place)(['items' => [['product_id' => $v->id, 'qty' => 1]]])->assertSessionHasErrors('items.0');
    ($this->place)(['items' => [['product_id' => $this->p->id, 'qty' => 1, 'discount' => '5000']]])->assertSessionHasErrors('items');
    ($this->place)(['advance' => '99999'])->assertSessionHasErrors('items');
    expect(Order::count())->toBe(0);
});

it('runs the lifecycle: reserve on confirm, deduct on ship, release on cancel', function () {
    ($this->place)(['items' => [['product_id' => $this->p->id, 'qty' => 4]]]);
    $o = Order::first();
    $go = fn ($s) => ($this->as)()->post("/app/orders/{$o->id}/status", ['status' => $s]);

    $go('shipped')->assertSessionHasErrors('status'); // illegal jump
    $go('confirmed')->assertSessionHasNoErrors();
    expect(($this->stock)()->reserved)->toBe(4)->and(($this->stock)()->on_hand)->toBe(10);
    $go('processing'); $go('ready_to_ship'); $go('shipped');
    expect(($this->stock)()->on_hand)->toBe(6)->and(($this->stock)()->reserved)->toBe(0);
    $go('delivered');
    expect($o->fresh()->status)->toBe('delivered')->and($o->histories()->count())->toBe(6);

    ($this->place)(['items' => [['product_id' => $this->p->id, 'qty' => 3]]]);
    $o2 = Order::latest('id')->first();
    ($this->as)()->post("/app/orders/{$o2->id}/status", ['status' => 'confirmed']);
    expect(($this->stock)()->reserved)->toBe(3);
    ($this->as)()->post("/app/orders/{$o2->id}/status", ['status' => 'cancelled']);
    expect(($this->stock)()->reserved)->toBe(0)->and($o2->fresh()->status)->toBe('cancelled');
});

it('refuses to confirm when stock is short and leaves the order unchanged', function () {
    ($this->place)(['items' => [['product_id' => $this->p->id, 'qty' => 50]]]);
    $o = Order::first();
    ($this->as)()->post("/app/orders/{$o->id}/status", ['status' => 'confirmed'])->assertSessionHasErrors('status');
    expect($o->fresh()->status)->toBe('pending_confirmation')->and(($this->stock)()->reserved)->toBe(0);
});

it('enforces permissions and tenant isolation', function () {
    [$viewer] = memberOf($this->tenant, 'viewer');
    ($this->as)($viewer)->get('/app/orders')->assertOk();
    ($this->as)($viewer)->post('/app/orders', ($this->payload)())->assertForbidden();
    ($this->place)();
    $o = Order::first();
    $cid = Customer::first()->id;
    ($this->as)($viewer)->post("/app/orders/{$o->id}/status", ['status' => 'confirmed'])->assertForbidden();
    [$staff] = memberOf($this->tenant, 'order_staff'); // may update but not cancel
    ($this->as)($staff)->post("/app/orders/{$o->id}/status", ['status' => 'cancelled'])->assertForbidden();

    [$u2, $t2] = memberOf();
    $b = fn () => $this->actingAs($u2)->withSession(['tenant_id' => $t2->id]);
    $b()->get("/app/orders/{$o->id}")->assertNotFound();
    $b()->get("/app/orders/{$o->id}/invoice")->assertNotFound();
    $b()->post("/app/orders/{$o->id}/status", ['status' => 'confirmed'])->assertNotFound();
    $b()->get("/app/customers/{$cid}")->assertNotFound();
    $b()->getJson('/app/orders/product-search?q=Mug')->assertOk()->assertJson([]);
});

it('numbers orders per tenant independently', function () {
    ($this->place)();
    [$u2, $t2] = memberOf();
    asTenant($t2);
    $p2 = Product::factory()->create();
    $this->actingAs($u2)->withSession(['tenant_id' => $t2->id])->post('/app/orders', ($this->payload)(['items' => [['product_id' => $p2->id, 'qty' => 1]]]));
    expect(Order::first()->order_number)->toBe('ORD-000001');
});

it('renders the invoice and searches sellable items', function () {
    ($this->place)();
    $o = Order::first();
    ($this->as)()->get("/app/orders/{$o->id}/invoice")->assertInertia(fn ($a) => $a->component('Orders/Invoice')->where('order.order_number', 'ORD-000001'));
    ($this->as)()->getJson('/app/orders/product-search?q=mug')->assertJsonPath('0.sku', 'MUG')->assertJsonPath('0.available', 10);
});

// ---------------------------------------------------------------- bulk order import
beforeEach(function () {
    $this->csvImport = function (string $csv) {
    ($this->as)()->post('/app/imports/orders', ['file' => UploadedFile::fake()->createWithContent('orders.csv', $csv)])->assertRedirect();
    $i = Import::withoutGlobalScopes()->latest('id')->first();
    ($this->as)()->post("/app/imports/{$i->id}/mapping", ['mapping' => $i->mapping, 'duplicate_policy' => 'skip'])->assertSessionHasNoErrors();

    return $i->refresh();
    };
});

it('imports orders grouped by external order id with all-or-nothing groups and duplicate skipping', function () {
    $csvImport = $this->csvImport;
    $tee = Product::factory()->create(['type' => 'variable', 'selling_price' => '600']);
    $tee->variants()->create(['sku' => 'TEE-S', 'options' => ['Size' => 'S']]);
    $csv = "external_order_id,customer_name,customer_phone,delivery_address,product_sku,quantity,delivery_charge,advance_paid\n"
        ."A-1,Rahim,01712345678,Dhanmondi,MUG,1,80,100\n"
        ."A-1,,,,TEE-S,2,,\n"
        ."B-1,Karim,01812345678,Chawkbazar,NOPE,1,0,0\n"      // unknown SKU
        ."B-1,,,,MUG,1,,\n"                                    // valid row, but its group is poisoned
        ."C-1,Sue,12,Somewhere,MUG,1,0,0\n";                   // bad phone
    $i = $csvImport($csv);
    expect($i->options['valid_rows'])->toBe(2)->and($i->options['invalid_rows'])->toBe(3);

    ($this->as)()->post("/app/imports/{$i->id}/commit")->assertRedirect();
    $i->refresh();
    expect($i->created_count)->toBe(1)->and($i->failed_count)->toBe(2)->and($i->status)->toBe('completed_with_errors');
    $o = Order::where('external_ref', 'A-1')->first();
    expect($o->items)->toHaveCount(2)->and($o->source)->toBe('import')->and((string) $o->total)->toBe('3280.00')->and((string) $o->cod_amount)->toBe('3180.00')
        ->and(Order::count())->toBe(1);

    // Re-importing the same external ids never duplicates
    $j = $csvImport("external_order_id,customer_name,customer_phone,delivery_address,product_sku,quantity\nA-1,Rahim,01712345678,Dhanmondi,MUG,1\n");
    ($this->as)()->post("/app/imports/{$j->id}/commit");
    expect(Order::count())->toBe(1)->and($j->refresh()->skipped_count)->toBe(1);
});

it('keeps order imports behind orders.import', function () {
    [$viewer] = memberOf($this->tenant, 'viewer');
    ($this->as)($viewer)->post('/app/imports/orders', ['file' => UploadedFile::fake()->createWithContent('o.csv', "a\n1")])->assertForbidden();
    ($this->as)()->get('/app/imports/orders/template')->assertOk();
});
