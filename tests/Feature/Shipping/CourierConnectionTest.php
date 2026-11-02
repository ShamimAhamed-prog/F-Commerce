<?php

use App\Models\AuditLog;
use App\Models\CourierIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

const KEY = 'test-api-key-AAAA1111';
const SECRET = 'test-secret-key-BBBB2222';

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    RateLimiter::clear('courier-test:1');
    [$this->user, $this->tenant] = memberOf();
    $this->as = fn ($u = null) => $this->actingAs($u ?? $this->user)->withSession(['tenant_id' => $this->tenant->id]);
    $this->save = fn (array $o = []) => ($this->as)()->post('/app/settings/couriers/steadfast', array_merge(['api_key' => KEY, 'secret_key' => SECRET], $o));
});

it('connects: sends both keys to the fixed platform host and marks the integration connected', function () {
    Http::fake(['*' => Http::response(['status' => 200], 200)]);
    ($this->save)()->assertSessionHas('success');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://portal.packzy.com/api/v1/') && $r->hasHeader('Api-Key', KEY) && $r->hasHeader('Secret-Key', SECRET));
    $i = CourierIntegration::first();
    expect($i->status)->toBe('connected')->and($i->last_tested_at)->not->toBeNull()->and($i->last_error_code)->toBeNull();
});

it('stores credentials encrypted at rest and never returns them to the browser', function () {
    Http::fake(['*' => Http::response([], 200)]);
    ($this->save)();

    $raw = DB::table('courier_integrations')->value('credentials');
    expect($raw)->not->toContain(KEY)->and($raw)->not->toContain(SECRET);
    expect(CourierIntegration::first()->credentials)->toBe(['api_key' => KEY, 'secret_key' => SECRET]);
    expect(CourierIntegration::first()->toArray())->not->toHaveKey('credentials');

    $page = ($this->as)()->get('/app/settings/couriers');
    $page->assertInertia(fn ($a) => $a->where('providers.0.integration.masked.api_key', '••••1111')->where('providers.0.integration.masked.secret_key', '••••••••'));
    expect($page->getContent())->not->toContain(KEY)->not->toContain(SECRET)->not->toContain('BBBB2222');
});

it('keeps secrets out of the audit trail and logs', function () {
    Http::fake(['*' => Http::response([], 200)]);
    ($this->save)();
    expect(json_encode(AuditLog::all()->toArray()))->not->toContain(KEY)->not->toContain(SECRET);
    expect(AuditLog::where('action', 'courier.connected')->exists())->toBeTrue()->and(AuditLog::where('action', 'courier.tested')->exists())->toBeTrue();
});

it('maps provider status codes to safe messages', function () {
    $cases = [[401, 'invalid_credentials', 'rejected these keys'], [403, 'not_allowed', 'inactive'], [429, 'rate_limited', 'hour'], [500, 'provider_error_500', 'Try again later']];
    $seq = Http::sequence();
    foreach ($cases as [$status]) {
        $seq->push(['message' => 'secret detail '.SECRET], $status);
    }
    Http::fake(['*' => $seq]);

    foreach ($cases as [$status, $code, $text]) {
        CourierIntegration::query()->delete();
        $res = ($this->save)();
        $res->assertSessionHas('error');
        expect(session('error'))->toContain($text)->not->toContain(SECRET);
        expect(CourierIntegration::first()->status)->toBe('failed')->and(CourierIntegration::first()->last_error_code)->toBe($code);
        RateLimiter::clear('courier-test:'.CourierIntegration::first()->id);
    }
});

it('reports an unreachable provider without crashing', function () {
    Http::fake(fn () => throw new ConnectionException('boom'));
    ($this->save)()->assertSessionHas('error');
    expect(CourierIntegration::first()->last_error_code)->toBe('unreachable');
});

it('stops testing after repeated failures so the provider lockout is never triggered', function () {
    Http::fake(['*' => Http::response([], 401)]);
    ($this->save)();
    $id = CourierIntegration::first()->id;
    foreach (range(1, 4) as $i) {
        ($this->as)()->post('/app/settings/couriers/steadfast/test');
    }
    Http::assertSentCount(3); // the 4th and 5th attempts never leave the server
    expect(session('error'))->toContain('Too many failed attempts');
});

it('rotates keys: blank fields keep the stored value', function () {
    Http::fake(['*' => Http::response([], 200)]);
    ($this->save)();
    ($this->as)()->post('/app/settings/couriers/steadfast', ['api_key' => '', 'secret_key' => 'new-secret-CCCC3333']);

    expect(CourierIntegration::first()->credentials)->toBe(['api_key' => KEY, 'secret_key' => 'new-secret-CCCC3333']);
    expect(AuditLog::where('action', 'courier.credentials_rotated')->exists())->toBeTrue();
});

it('requires both keys for a new connection and rejects odd characters', function () {
    ($this->as)()->post('/app/settings/couriers/steadfast', ['api_key' => KEY])->assertSessionHasErrors('secret_key');
    ($this->as)()->post('/app/settings/couriers/steadfast', ['api_key' => "bad key\n", 'secret_key' => SECRET])->assertSessionHasErrors('api_key');
    expect(CourierIntegration::count())->toBe(0);
});

it('does not let a tenant choose the provider host or an unknown provider', function () {
    Http::fake(['*' => Http::response([], 200)]);
    ($this->save)(['base_url' => 'http://169.254.169.254/', 'host' => 'evil.example']);
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://portal.packzy.com/'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254') || str_contains($r->url(), 'evil.example'));
    ($this->as)()->post('/app/settings/couriers/redx', ['api_key' => 'a', 'secret_key' => 'b'])->assertNotFound(); // no REDX docs yet
});

it('gives each tenant its own account with no leakage', function () {
    Http::fake(['*' => Http::response([], 200)]);
    ($this->save)();
    [$u2, $t2] = memberOf();
    $b = fn () => $this->actingAs($u2)->withSession(['tenant_id' => $t2->id]);

    $b()->get('/app/settings/couriers')->assertInertia(fn ($a) => $a->where('providers.0.integration', null));
    $b()->post('/app/settings/couriers/steadfast/test')->assertNotFound();
    $b()->delete('/app/settings/couriers/steadfast')->assertNotFound();
    $b()->post('/app/settings/couriers/steadfast', ['api_key' => 'other-key-ZZZZ', 'secret_key' => 'other-secret'])->assertSessionHasNoErrors();
    asTenant($this->tenant);
    expect(CourierIntegration::first()->credentials['api_key'])->toBe(KEY);
    expect(CourierIntegration::withoutGlobalScopes()->count())->toBe(2);
});

it('is limited to integrations.manage and can disconnect', function () {
    Http::fake(['*' => Http::response([], 200)]);
    [$mgr] = memberOf($this->tenant, 'manager');
    ($this->as)($mgr)->get('/app/settings/couriers')->assertForbidden();
    ($this->as)($mgr)->post('/app/settings/couriers/steadfast', ['api_key' => KEY, 'secret_key' => SECRET])->assertForbidden();

    ($this->save)();
    ($this->as)()->delete('/app/settings/couriers/steadfast')->assertRedirect();
    expect(CourierIntegration::count())->toBe(0)->and(AuditLog::where('action', 'courier.disconnected')->exists())->toBeTrue();
});
