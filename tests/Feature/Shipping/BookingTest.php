<?php

use App\Domain\Inventory\StockService;
use App\Domain\Sales\OrderService;
use App\Domain\Shipping\ShipmentService;
use App\Models\AuditLog;
use App\Models\CourierIntegration;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

const AK = 'ak-TEST-1111';
const SK = 'sk-TEST-2222';

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    [$this->user, $this->tenant] = memberOf();
    asTenant($this->tenant);
    $this->actingAs($this->user);
    $this->as = fn ($u = null) => $this->actingAs($u ?? $this->user)->withSession(['tenant_id' => $this->tenant->id]);
    $this->integration = CourierIntegration::create(['provider' => 'steadfast', 'display_name' => 'Steadfast', 'credentials' => ['api_key' => AK, 'secret_key' => SK], 'status' => 'connected']);
    $this->p = Product::factory()->create(['selling_price' => '1500', 'purchase_cost' => '900']);
    app(StockService::class)->record($this->p->id, null, 'opening', 50, '900');
    $this->svc = app(OrderService::class);
    $this->order = function (string $status = 'ready_to_ship', array $o = []) {
        $order = $this->svc->create(array_merge(['customer_name' => 'Rahim Uddin', 'phone' => '+880 1712-345678', 'address' => 'House 5, Dhanmondi', 'area' => 'Dhaka',
            'shipping_charge' => '80', 'advance' => '100', 'notes' => 'Call first', 'items' => [['product_id' => $this->p->id, 'qty' => 1]]], $o));
        foreach (['pending_confirmation', 'confirmed', 'processing', 'ready_to_ship', 'shipped'] as $s) {
            if ($s === 'pending_confirmation') { continue; }
            if (array_search($s, ['confirmed', 'processing', 'ready_to_ship', 'shipped']) > array_search($status, ['confirmed', 'processing', 'ready_to_ship', 'shipped'])) { break; }
            $this->svc->transition($order, $s);
        }

        return $order->refresh();
    };
    $this->ship = fn (Order $o) => ($this->as)()->post("/app/orders/{$o->id}/ship", ['provider' => 'steadfast']);
    $ok = fn (array $c = []) => ['status' => 200, 'message' => 'Consignment has been created successfully.', 'consignment' => array_merge(['consignment_id' => 1424107, 'invoice' => 'x', 'tracking_code' => '15BAEB8A', 'status' => 'in_review'], $c)];
    $this->okBody = $ok;
});

it('books the parcel: right headers, right payload, ids stored, order untouched, audited', function () {
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200)]);
    $o = ($this->order)();
    ($this->ship)($o)->assertSessionHas('success');

    Http::assertSent(function (Request $r) use ($o) {
        return $r->method() === 'POST' && $r->url() === 'https://portal.packzy.com/api/v1/create_order'
            && $r->hasHeader('Api-Key', AK) && $r->hasHeader('Secret-Key', SK)
            && $r->data() === ['invoice' => $o->order_number, 'recipient_name' => 'Rahim Uddin', 'recipient_phone' => '01712345678',
                'recipient_address' => 'House 5, Dhanmondi, Dhaka', 'cod_amount' => 1480, 'note' => 'Call first'];
    });
    $s = Shipment::first();
    expect($s->state)->toBe('booked')->and($s->consignment_id)->toBe('1424107')->and($s->tracking_code)->toBe('15BAEB8A')->and($s->provider_status)->toBe('in_review')
        ->and($s->invoice)->toBe($o->order_number)->and((string) $s->cod_amount)->toBe('1480.00');
    expect($o->fresh()->status)->toBe('ready_to_ship');
    expect(AuditLog::where('action', 'shipment.booked')->exists())->toBeTrue()->and($s->events()->count())->toBe(2);
});

it('cleans provider-unsafe characters, trims lengths and sends decimals only when needed', function () {
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200)]);
    $o = ($this->order)('ready_to_ship', ['customer_name' => 'Ra{h}im <b>$Uddin;', 'address' => str_repeat('A', 600), 'area' => null, 'notes' => null]);
    Order::whereKey($o->id)->update(['cod_amount' => '1480.50']);
    ($this->ship)($o->fresh());

    Http::assertSent(function (Request $r) {
        $d = $r->data();
        return $d['recipient_name'] === 'Ra h im b Uddin' && mb_strlen($d['recipient_address']) === 490 && $d['cod_amount'] === 1480.5 && ! array_key_exists('note', $d);
    });
});

it('refuses to book when it should not, sending nothing to the courier', function () {
    $pending = $this->svc->create(['customer_name' => 'A', 'phone' => '01712345678', 'address' => 'X', 'items' => [['product_id' => $this->p->id, 'qty' => 1]]]);
    ($this->ship)($pending)->assertSessionHasErrors('courier'); // not confirmed yet

    $shipped = ($this->order)('shipped');
    ($this->ship)($shipped)->assertSessionHasErrors('courier');

    $badPhone = ($this->order)('ready_to_ship');
    Order::whereKey($badPhone->id)->update(['phone' => '12345']);
    ($this->ship)($badPhone->fresh())->assertSessionHasErrors('courier');

    $this->integration->update(['status' => 'failed']);
    ($this->ship)(($this->order)())->assertSessionHasErrors('courier');

    Http::assertNothingSent();
    expect(Shipment::count())->toBe(0);
});

it('cannot be double-booked: the second click never reaches the courier', function () {
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200)]);
    $o = ($this->order)();
    ($this->ship)($o)->assertSessionHas('success');
    ($this->ship)($o)->assertSessionHasErrors('courier');
    Http::assertSentCount(1);
    expect(Shipment::count())->toBe(1);

    // an in-flight booking (row committed before the HTTP call) blocks too
    $o2 = ($this->order)();
    Shipment::create(['order_id' => $o2->id, 'provider' => 'steadfast', 'invoice' => $o2->order_number, 'state' => 'booking', 'cod_amount' => 0]);
    ($this->ship)($o2)->assertSessionHasErrors('courier');
    Http::assertSentCount(1);
});

it('a timeout is "unknown", is never retried, and blocks another booking until reconciled', function () {
    $calls = 0;
    Http::fake(['*/create_order' => function () use (&$calls) { $calls++; throw new ConnectionException('timed out'); }]);
    $o = ($this->order)();
    ($this->ship)($o)->assertSessionHas('error');
    $s = Shipment::first();
    expect($s->state)->toBe('booking_unknown')->and($s->error_code)->toBe('timeout')->and($calls)->toBe(1); // not retried

    ($this->ship)($o)->assertSessionHasErrors('courier');
    expect($calls)->toBe(1); // blocked before any new request
});

it('treats 5xx and unreadable replies as unknown, and rejections as failed', function () {
    $cases = [
        ['boom', 502, 'booking_unknown'],
        [['status' => 200, 'message' => 'ok'], 200, 'booking_unknown'],                       // 200 but no consignment id
        ['<html>', 200, 'booking_unknown'],
        [['status' => 400, 'message' => 'Invalid phone'], 200, 'failed'],                      // rejection reported inside a 200
        [['message' => 'bad', 'errors' => ['recipient_phone' => ['The phone is invalid.']]], 422, 'failed'],
        ['Unauthorized Access', 401, 'failed'],
    ];
    $seq = Http::sequence();
    foreach ($cases as [$body, $status]) {
        $seq->push($body, $status);
    }
    Http::fake(['*/create_order' => $seq]);

    foreach ($cases as $i => [, , $expected]) {
        $o = ($this->order)();
        ($this->ship)($o);
        expect(Shipment::where('order_id', $o->id)->first()->state)->toBe($expected, "case {$i}");
    }
    expect(Shipment::where('error_code', 'invalid_credentials')->first()->message)->toContain('Settings')->not->toContain(SK)->not->toContain(AK);
    expect(Shipment::where('error_code', 'rejected')->orderByDesc('id')->first()->message)->toContain('phone is invalid');
});

it('allows a corrected retry after a definite failure, reusing the same invoice', function () {
    Http::fake(['*/create_order' => Http::sequence()->push(['message' => 'bad', 'errors' => ['recipient_address' => ['Address too short.']]], 422)->push(($this->okBody)(), 200)]);
    $o = ($this->order)();
    ($this->ship)($o)->assertSessionHas('error');
    expect(Shipment::first()->state)->toBe('failed');
    ($this->ship)($o)->assertSessionHas('success');

    expect(Shipment::count())->toBe(1)->and(Shipment::first()->state)->toBe('booked')->and(Shipment::first()->attempt)->toBe(1);
    Http::assertSentCount(2);
});

it('reconcile: found at the courier -> booked', function () {
    Http::fake(['*/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'pending'], 200)]);
    $o = ($this->order)();
    $s = Shipment::create(['order_id' => $o->id, 'courier_integration_id' => $this->integration->id, 'provider' => 'steadfast', 'invoice' => $o->order_number, 'state' => 'booking_unknown', 'cod_amount' => 0, 'error_code' => 'timeout']);

    ($this->as)()->post("/app/shipments/{$s->id}/reconcile")->assertSessionHas('success');
    expect($s->fresh()->state)->toBe('booked')->and($s->fresh()->provider_status)->toBe('pending')->and($s->fresh()->message)->toBeNull();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/status_by_invoice/'.$o->order_number));
});

it('reconcile: a 401 is ambiguous, so "not booked" needs valid keys AND the provider cache window to pass', function () {
    $o = ($this->order)();
    $s = Shipment::create(['order_id' => $o->id, 'courier_integration_id' => $this->integration->id, 'provider' => 'steadfast', 'invoice' => $o->order_number, 'state' => 'booking_unknown', 'cod_amount' => 0]);
    $reconcile = fn () => ($this->as)()->post("/app/shipments/{$s->id}/reconcile");

    // keys are good, but we only just tried: cannot conclude yet
    Http::fake(['*/status_by_invoice/*' => Http::response('Unauthorized Access', 401), '*/get_balance' => Http::response(['status' => 200, 'current_balance' => 0], 200)]);
    $reconcile()->assertSessionHasErrors('courier');
    expect($s->fresh()->state)->toBe('booking_unknown');

    // later, keys still good -> the parcel does not exist at the courier: safe to try again
    Shipment::whereKey($s->id)->update(['updated_at' => now()->subMinutes(5)]);
    $reconcile()->assertSessionHasNoErrors();
    expect($s->fresh()->state)->toBe('failed')->and($s->fresh()->error_code)->toBe('not_found_at_provider');
});

it('reconcile: bad keys or an unreachable courier never conclude anything', function () {
    $o = ($this->order)();
    $s = Shipment::create(['order_id' => $o->id, 'courier_integration_id' => $this->integration->id, 'provider' => 'steadfast', 'invoice' => $o->order_number, 'state' => 'booking_unknown', 'cod_amount' => 0, 'updated_at' => now()->subHour()]);
    Shipment::whereKey($s->id)->update(['updated_at' => now()->subHour()]);

    Http::fake(['*/status_by_invoice/*' => Http::response('Unauthorized Access', 401), '*/get_balance' => Http::response('Unauthorized Access', 401)]);
    ($this->as)()->post("/app/shipments/{$s->id}/reconcile")->assertSessionHasErrors('courier');
    expect($s->fresh()->state)->toBe('booking_unknown');

    Http::fake(['*/status_by_invoice/*' => fn () => throw new ConnectionException('down')]);
    ($this->as)()->post("/app/shipments/{$s->id}/reconcile")->assertSessionHasErrors('courier');
    expect($s->fresh()->state)->toBe('booking_unknown');
});

it('only reconciles bookings that are actually unresolved', function () {
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200)]);
    $o = ($this->order)();
    ($this->ship)($o);
    ($this->as)()->post('/app/shipments/'.Shipment::first()->id.'/reconcile')->assertSessionHasErrors('courier');
});

// ------------------------------------------------------------------- status sync
it('delivery confirmed by the courier moves a SHIPPED order to delivered; it does not settle COD', function () {
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200), '*/status_by_invoice/*' => Http::sequence()->push(['delivery_status' => 'in_review'], 200)->push(['delivery_status' => 'delivered'], 200)]);
    $o = ($this->order)();
    ($this->ship)($o);
    $this->svc->transition($o, 'shipped');
    $s = Shipment::first();

    ($this->as)()->post("/app/shipments/{$s->id}/refresh");
    expect($o->fresh()->status)->toBe('shipped');
    ($this->as)()->post("/app/shipments/{$s->id}/refresh");
    expect($o->fresh()->status)->toBe('delivered')->and($s->fresh()->provider_status)->toBe('delivered')->and($s->events()->pluck('status')->all())->toContain('delivered');
    expect(app(App\Domain\Accounting\ReportService::class)->balanceOf(App\Domain\Accounting\ChartOfAccounts::COD_RECEIVABLE))->toBe('1480.00'); // still owed
});

it('does not act on rider-only statuses, nor on orders that are not shipped yet', function () {
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200), '*/status_by_invoice/*' => Http::sequence()->push(['delivery_status' => 'delivered_approval_pending'], 200)->push(['delivery_status' => 'delivered'], 200)]);
    $o = ($this->order)();
    ($this->ship)($o);
    $this->svc->transition($o, 'shipped');
    $s = Shipment::first();
    ($this->as)()->post("/app/shipments/{$s->id}/refresh");
    expect($o->fresh()->status)->toBe('shipped')->and($s->fresh()->provider_status)->toBe('delivered_approval_pending');

    $notShipped = ($this->order)();
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200), '*/status_by_invoice/*' => Http::response(['delivery_status' => 'delivered'], 200)]);
    ($this->ship)($notShipped);
    ($this->as)()->post('/app/shipments/'.Shipment::where('order_id', $notShipped->id)->value('id').'/refresh');
    expect($notShipped->fresh()->status)->toBe('ready_to_ship'); // never skips the lifecycle
});

it('a cancelled parcel marks the shipped order as delivery failed and frees it for re-shipping with a new invoice', function () {
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200), '*/status_by_invoice/*' => Http::response(['delivery_status' => 'cancelled'], 200)]);
    $o = ($this->order)();
    ($this->ship)($o);
    $this->svc->transition($o, 'shipped');
    ($this->as)()->post('/app/shipments/'.Shipment::first()->id.'/refresh');
    expect($o->fresh()->status)->toBe('delivery_failed');

    // a cancelled parcel no longer blocks a new booking (needs a bookable order state)
    expect(Shipment::first()->isActive())->toBeFalse();
    Order::whereKey($o->id)->update(['status' => 'ready_to_ship']);
    Http::fake(['*/create_order' => Http::response(($this->okBody)(['consignment_id' => 999]), 200)]);
    ($this->ship)($o->fresh());
    expect(Shipment::orderBy('id')->pluck('invoice')->all())->toBe([$o->order_number, "{$o->order_number}-2"]);
});

it('never overwrites known status with an unreadable answer', function () {
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200), '*/status_by_invoice/*' => Http::response('Unauthorized Access', 401)]);
    $o = ($this->order)();
    ($this->ship)($o);
    ($this->as)()->post('/app/shipments/'.Shipment::first()->id.'/refresh');
    expect(Shipment::first()->provider_status)->toBe('in_review');
});

it('syncs unfinished parcels on a schedule and skips finished ones', function () {
    Http::fake(['*/status_by_invoice/*' => Http::response(['delivery_status' => 'delivered'], 200)]);
    $a = ($this->order)('shipped');
    $b = ($this->order)('shipped');
    $mk = fn ($o, $status) => Shipment::create(['order_id' => $o->id, 'courier_integration_id' => $this->integration->id, 'provider' => 'steadfast', 'invoice' => $o->order_number, 'state' => 'booked', 'provider_status' => $status, 'cod_amount' => 0]);
    $mk($a, 'in_review');
    $mk($b, 'cancelled'); // final: never polled again

    $this->artisan('fc:courier-sync')->assertSuccessful();
    Http::assertSentCount(1);
    expect($a->fresh()->status)->toBe('delivered')->and($b->fresh()->status)->toBe('shipped');
});

// -------------------------------------------------------- permissions, isolation, safety
it('limits booking to shipments.manage and keeps shipments inside the tenant', function () {
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200)]);
    $o = ($this->order)();
    [$orderStaff] = memberOf($this->tenant, 'order_staff'); // shipments.view only
    [$viewer] = memberOf($this->tenant, 'viewer');
    ($this->as)($orderStaff)->post("/app/orders/{$o->id}/ship", ['provider' => 'steadfast'])->assertForbidden();
    ($this->as)($viewer)->post("/app/orders/{$o->id}/ship", ['provider' => 'steadfast'])->assertForbidden();
    Http::assertNothingSent();

    [$delivery] = memberOf($this->tenant, 'delivery_staff'); // shipments.manage
    ($this->as)($delivery)->post("/app/orders/{$o->id}/ship", ['provider' => 'steadfast'])->assertSessionHas('success');

    $sid = Shipment::first()->id;
    [$u2, $t2] = memberOf();
    $b = fn () => $this->actingAs($u2)->withSession(['tenant_id' => $t2->id]);
    $b()->post("/app/orders/{$o->id}/ship", ['provider' => 'steadfast'])->assertNotFound();
    $b()->post("/app/shipments/{$sid}/refresh")->assertNotFound();
    $b()->post("/app/shipments/{$sid}/reconcile")->assertNotFound();
    $b()->get('/app/shipments')->assertInertia(fn ($a) => $a->has('shipments.data', 0));
});

it('uses the tenant\'s own credentials and never stores or shows secrets', function () {
    Http::fake(['*/create_order' => Http::response(($this->okBody)(), 200)]);
    $o = ($this->order)();
    ($this->ship)($o);
    $dump = json_encode(Shipment::all()->toArray()).json_encode(App\Models\ShipmentEvent::all()->toArray()).json_encode(AuditLog::all()->toArray());
    expect($dump)->not->toContain(AK)->not->toContain(SK);

    $page = ($this->as)()->get("/app/orders/{$o->id}")->assertInertia(fn ($a) => $a->has('shipments', 1)->where('shipments.0.tracking_code', '15BAEB8A')->where('canShip', false)->has('couriers', 1));
    expect($page->getContent())->not->toContain(AK)->not->toContain(SK);
});

it('shows the courier section data for an unshipped order and lists shipments', function () {
    $o = ($this->order)();
    ($this->as)()->get("/app/orders/{$o->id}")->assertInertia(fn ($a) => $a->where('canShip', true)->where('canManageShipments', true)->where('couriers.0.provider', 'steadfast')->where('courierSetupUrl', '/app/settings/couriers'));
    ($this->as)()->get('/app/shipments')->assertOk()->assertInertia(fn ($a) => $a->component('Shipments/Index'));
});
