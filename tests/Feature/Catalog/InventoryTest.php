<?php

use App\Domain\Inventory\StockService;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockBalance;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    [$this->user, $this->tenant] = memberOf();
    asTenant($this->tenant);
    $this->actingAs($this->user);
    $this->as = fn ($u = null) => $this->actingAs($u ?? $this->user)->withSession(['tenant_id' => $this->tenant->id]);
    $this->stock = app(StockService::class);
    $this->p = Product::factory()->create(['reorder_threshold' => 5, 'name' => 'Mug']);
    $this->stock->record($this->p->id, null, 'opening', 10, '100');
});

it('lists sellable units with stock, flags low stock and filters', function () {
    $v = Product::factory()->create(['type' => 'variable', 'name' => 'Tee', 'reorder_threshold' => 3]);
    $s = $v->variants()->create(['sku' => 'TEE-S', 'options' => ['Size' => 'S']]);
    $this->stock->record($v->id, $s->id, 'opening', 2, '50');

    ($this->as)()->get('/app/inventory')->assertInertia(fn (Assert $a) => $a->has('units.data', 2)
        ->where('units.data.0.name', 'Mug')->where('units.data.0.on_hand', 10)->where('units.data.0.low', false)
        ->where('units.data.1.sku', 'TEE-S')->where('units.data.1.low', true)->where('units.data.1.options.Size', 'S')
        ->where('valuation', '1100.00')); // 10*100 + 2*50

    ($this->as)()->get('/app/inventory?low_stock=1')->assertInertia(fn (Assert $a) => $a->has('units.data', 1)->where('units.data.0.sku', 'TEE-S'));
});

it('hides cost and valuation from roles without products.costs.view', function () {
    [$mgr] = memberOf($this->tenant, 'manager');
    ($this->as)($mgr)->get('/app/inventory')->assertInertia(fn (Assert $a) => $a->where('valuation', null)->where('units.data.0.avg_cost', null));
});

it('adjusts by delta with a required reason and writes ledger + audit', function () {
    ($this->as)()->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'mode' => 'delta', 'quantity' => -3, 'reason' => 'Damaged in storage'])->assertSessionHasNoErrors();

    expect(StockBalance::first()->on_hand)->toBe(7);
    expect(AuditLog::where('action', 'inventory.adjusted')->where('user_id', $this->user->id)->exists())->toBeTrue();
    ($this->as)()->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'mode' => 'delta', 'quantity' => 1])->assertSessionHasErrors('reason');
});

it('adjusts by counted quantity', function () {
    ($this->as)()->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'mode' => 'count', 'quantity' => 14, 'reason' => 'Stock take'])->assertSessionHasNoErrors();
    expect(StockBalance::first()->on_hand)->toBe(14);
    ($this->as)()->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'mode' => 'count', 'quantity' => 14, 'reason' => 'Stock take'])->assertSessionHasErrors('quantity');
});

it('cannot adjust below zero', function () {
    ($this->as)()->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'mode' => 'delta', 'quantity' => -11, 'reason' => 'Oops'])->assertSessionHasErrors('quantity');
    expect(StockBalance::first()->on_hand)->toBe(10);
});

it('requires a variant for variable products and rejects one for simple products', function () {
    $v = Product::factory()->create(['type' => 'variable']);
    $variant = $v->variants()->create(['sku' => 'V-1', 'options' => ['a' => 'b']]);

    ($this->as)()->post('/app/inventory/adjust', ['product_id' => $v->id, 'mode' => 'delta', 'quantity' => 3, 'reason' => 'Receive'])->assertSessionHasErrors('variant_id');
    ($this->as)()->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'variant_id' => $variant->id, 'mode' => 'delta', 'quantity' => 3, 'reason' => 'Receive'])->assertSessionHasErrors('variant_id');
    ($this->as)()->post('/app/inventory/adjust', ['product_id' => $v->id, 'variant_id' => $variant->id, 'mode' => 'delta', 'quantity' => 3, 'reason' => 'Receive'])->assertSessionHasNoErrors();
});

it('only accepts a unit cost for incoming stock from users who may set costs', function () {
    ($this->as)()->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'mode' => 'delta', 'quantity' => 10, 'reason' => 'Purchase', 'unit_cost' => '200'])->assertSessionHasNoErrors();
    expect((string) StockBalance::first()->avg_cost)->toBe('150.0000');

    [$mgr] = memberOf($this->tenant, 'manager');
    ($this->as)($mgr)->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'mode' => 'delta', 'quantity' => 20, 'reason' => 'Purchase', 'unit_cost' => '1'])->assertSessionHasNoErrors();
    expect((string) StockBalance::first()->avg_cost)->toBe('150.0000'); // ignored, valued at current average
});

it('enforces inventory.adjust and tenant isolation', function () {
    [$viewer] = memberOf($this->tenant, 'viewer');
    ($this->as)($viewer)->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'mode' => 'delta', 'quantity' => 1, 'reason' => 'nope'])->assertForbidden();
    ($this->as)($viewer)->get('/app/inventory')->assertOk();

    [$u2, $t2] = memberOf();
    $b = fn () => $this->actingAs($u2)->withSession(['tenant_id' => $t2->id]);
    $b()->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'mode' => 'delta', 'quantity' => 1, 'reason' => 'steal'])->assertNotFound();
    $b()->getJson("/app/inventory/movements?product_id={$this->p->id}")->assertOk()->assertJson([]);
    asTenant($this->tenant);
    expect(StockBalance::first()->on_hand)->toBe(10);
});

it('returns the movement history', function () {
    ($this->as)()->getJson("/app/inventory/movements?product_id={$this->p->id}")->assertOk()->assertJsonPath('0.type', 'opening')->assertJsonPath('0.qty', 10);
});

it('shows low-stock and missing-image counts on the dashboard', function () {
    $this->stock->record($this->p->id, null, 'sale', -6); // 4 <= 5
    ($this->as)()->get('/app/dashboard')->assertInertia(fn (Assert $a) => $a->where('attention.low_stock', 1)->where('attention.missing_images', 1)->where('attention.low_stock_items.0.name', 'Mug'));

    [$del] = memberOf($this->tenant, 'delivery_staff');
    ($this->as)($del)->get('/app/dashboard')->assertInertia(fn (Assert $a) => $a->where('attention.low_stock', null)->where('finance', null)->where('profit', null));
});
