<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    [$this->user, $this->tenant] = memberOf();
    $this->as = fn () => $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id]);
    $this->payload = fn (array $o = []) => array_merge([
        'name' => 'Cotton Panjabi', 'sku' => 'PNJ-001', 'type' => 'simple', 'selling_price' => '1500.00',
        'purchase_cost' => '900.00', 'status' => 'draft', 'category' => 'Clothing', 'brand' => 'Aarong',
        'opening_stock' => 12,
    ], $o);
});

it('creates a simple product with opening stock through the ledger', function () {
    ($this->as)()->post('/app/products', ($this->payload)())->assertRedirect();

    asTenant($this->tenant);
    $p = Product::where('sku', 'PNJ-001')->firstOrFail();
    expect($p->category->name)->toBe('Clothing')
        ->and(StockBalance::where('product_id', $p->id)->value('on_hand'))->toBe(12)
        ->and((string) StockBalance::where('product_id', $p->id)->value('avg_cost'))->toBe('900.0000');
    expect(AuditLog::where('action', 'product.created')->where('tenant_id', $this->tenant->id)->exists())->toBeTrue();
});

it('requires a unique SKU per tenant but allows the same SKU in another tenant', function () {
    ($this->as)()->post('/app/products', ($this->payload)())->assertRedirect();
    ($this->as)()->post('/app/products', ($this->payload)(['name' => 'Dup']))->assertSessionHasErrors('sku');

    [$u2, $t2] = memberOf();
    $this->actingAs($u2)->withSession(['tenant_id' => $t2->id])->post('/app/products', ($this->payload)())->assertSessionHasNoErrors();
});

it('shares the SKU namespace between products and variants', function () {
    ($this->as)()->post('/app/products', ($this->payload)([
        'type' => 'variable', 'sku' => 'TEE', 'opening_stock' => null,
        'variants' => [
            ['sku' => 'TEE-S', 'options' => ['Size' => 'S'], 'opening_stock' => 4],
            ['sku' => 'TEE-M', 'options' => ['Size' => 'M'], 'selling_price' => '700.00'],
        ],
    ]))->assertSessionHasNoErrors();

    asTenant($this->tenant);
    $p = Product::where('sku', 'TEE')->firstOrFail();
    expect($p->variants)->toHaveCount(2)
        ->and(StockBalance::where('product_id', $p->id)->where('variant_id', $p->variants[0]->id)->value('on_hand'))->toBe(4);

    ($this->as)()->post('/app/products', ($this->payload)(['sku' => 'TEE-S']))->assertSessionHasErrors('sku');
    ($this->as)()->post('/app/products', ($this->payload)([
        'type' => 'variable', 'sku' => 'NEW', 'variants' => [['sku' => 'TEE', 'options' => ['Size' => 'L']]],
    ]))->assertSessionHasErrors('variants.0.sku');
});

it('rejects duplicate variant SKUs inside one product and bad SKU characters', function () {
    ($this->as)()->post('/app/products', ($this->payload)([
        'type' => 'variable', 'variants' => [
            ['sku' => 'A-1', 'options' => ['S' => '1']], ['sku' => 'A-1', 'options' => ['S' => '2']],
        ],
    ]))->assertSessionHasErrors();
    ($this->as)()->post('/app/products', ($this->payload)(['sku' => 'bad sku!']))->assertSessionHasErrors('sku');
});

it('validates money precision and status', function () {
    ($this->as)()->post('/app/products', ($this->payload)(['selling_price' => '10.999', 'status' => 'bogus']))
        ->assertSessionHasErrors(['selling_price', 'status']);
    ($this->as)()->post('/app/products', ($this->payload)(['selling_price' => '-1']))->assertSessionHasErrors('selling_price');
});

it('updates a product and keeps its own SKU valid', function () {
    asTenant($this->tenant);
    $p = Product::factory()->create(['sku' => 'KEEP']);

    ($this->as)()->put("/app/products/{$p->id}", ($this->payload)(['sku' => 'KEEP', 'name' => 'Renamed', 'opening_stock' => null]))
        ->assertSessionHasNoErrors();
    expect($p->fresh()->name)->toBe('Renamed');
});

it('never exposes or accepts purchase cost for roles without products.costs.view', function () {
    [$mgr] = memberOf($this->tenant, 'manager');
    expect($mgr->hasTenantPermission('products.costs.view', $this->tenant->id))->toBeFalse();

    $this->actingAs($mgr)->withSession(['tenant_id' => $this->tenant->id])
        ->post('/app/products', ($this->payload)(['sku' => 'MGR-1', 'purchase_cost' => '777.00']))->assertRedirect();

    asTenant($this->tenant);
    $p = Product::where('sku', 'MGR-1')->first();
    expect((string) $p->purchase_cost)->toBe('0.00'); // input ignored

    $p->update(['purchase_cost' => '55.00']);
    $this->actingAs($mgr)->withSession(['tenant_id' => $this->tenant->id])->get("/app/products/{$p->id}/edit")
        ->assertInertia(fn (Assert $a) => $a->where('product.purchase_cost', null)->where('showCost', false));

    $this->actingAs($mgr)->withSession(['tenant_id' => $this->tenant->id])->put("/app/products/{$p->id}", ($this->payload)(['sku' => 'MGR-1', 'purchase_cost' => '1.00', 'opening_stock' => null]));
    expect((string) $p->fresh()->purchase_cost)->toBe('55.00');
});

it('enforces product permissions per role', function () {
    [$viewer] = memberOf($this->tenant, 'viewer');
    $c = fn () => $this->actingAs($viewer)->withSession(['tenant_id' => $this->tenant->id]);

    $c()->post('/app/products', ($this->payload)())->assertForbidden();
    $c()->get('/app/products/create')->assertForbidden();
    $c()->get('/app/products')->assertOk();
});

it('cannot read, edit or delete another tenant product', function () {
    $other = Tenant::factory()->create();
    asTenant($other);
    $theirs = Product::factory()->create();
    app(\App\Domain\Tenancy\CurrentTenant::class)->forget();

    ($this->as)()->get("/app/products/{$theirs->id}/edit")->assertNotFound();
    ($this->as)()->put("/app/products/{$theirs->id}", ($this->payload)())->assertNotFound();
    ($this->as)()->delete("/app/products/{$theirs->id}")->assertNotFound();
    expect(Product::withoutGlobalScopes()->find($theirs->id))->not->toBeNull();
});

it('archives instead of deleting products that have stock history', function () {
    ($this->as)()->post('/app/products', ($this->payload)())->assertRedirect();
    asTenant($this->tenant);
    $p = Product::where('sku', 'PNJ-001')->first();

    ($this->as)()->delete("/app/products/{$p->id}")->assertRedirect('/app/products');
    expect($p->fresh()->status)->toBe('archived');

    $q = Product::factory()->create();
    ($this->as)()->delete("/app/products/{$q->id}");
    expect(Product::find($q->id))->toBeNull();
});

it('blocks activation without an image when the tenant requires one', function () {
    $this->tenant->update(['settings' => ['require_image_before_activation' => true]]);

    ($this->as)()->post('/app/products', ($this->payload)(['status' => 'active']))->assertSessionHasErrors('status');
    ($this->as)()->post('/app/products', ($this->payload)(['status' => 'draft', 'sku' => 'D-1']))->assertSessionHasNoErrors();
});

it('lists products with server-side pagination and search', function () {
    asTenant($this->tenant);
    Product::factory()->count(30)->create();
    Product::factory()->create(['name' => 'Findable Item', 'sku' => 'FIND-ME']);

    ($this->as)()->get('/app/products?per_page=1000')->assertInertia(fn (Assert $a) => $a->has('products.data', 31)); // max 100 cap, 31 rows
    ($this->as)()->get('/app/products?search=FIND-ME')->assertInertia(fn (Assert $a) => $a->has('products.data', 1));
    ($this->as)()->get('/app/products?search=%25')->assertInertia(fn (Assert $a) => $a->has('products.data', 0)); // wildcard is escaped
    ($this->as)()->get('/app/products')->assertInertia(fn (Assert $a) => $a->where('products.per_page', 25));
});

it('accepts blank optional fields the way the browser form sends them', function () {
    ($this->as)()->post('/app/products', [
        'name' => 'Bare', 'sku' => 'BARE-1', 'type' => 'simple', 'selling_price' => '10', 'status' => 'draft',
        'purchase_cost' => '', 'compare_at_price' => '', 'tax_rate' => '', 'barcode' => '', 'weight' => '', 'dimensions' => '',
        'reorder_threshold' => '', 'opening_stock' => '', 'category' => '', 'brand' => '', 'description' => '',
    ])->assertSessionHasNoErrors()->assertRedirect();

    asTenant($this->tenant);
    $p = Product::where('sku', 'BARE-1')->firstOrFail();
    expect((string) $p->purchase_cost)->toBe('0.00')->and($p->reorder_threshold)->toBe(0)->and($p->category_id)->toBeNull();

    ($this->as)()->put("/app/products/{$p->id}", ['name' => 'Bare', 'sku' => 'BARE-1', 'type' => 'simple', 'selling_price' => '10', 'status' => 'draft', 'purchase_cost' => '', 'reorder_threshold' => ''])
        ->assertSessionHasNoErrors();
});
