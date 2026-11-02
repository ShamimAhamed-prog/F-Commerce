<?php

use App\Http\Controllers\MaintenanceController;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('maintenance-fail:127.0.0.1');
    $this->key = str_repeat('k', 40);
});

it('is disabled (plain 404) when no key is configured, even with a key supplied', function () {
    config(['security.maintenance_key' => null]);
    $this->get('/maintenance/clear?key='.$this->key)->assertNotFound();
    config(['security.maintenance_key' => 'short']);
    $this->get('/maintenance/clear?key=short')->assertNotFound(); // too weak to ever enable
});

it('answers every failure identically so nothing is revealed', function () {
    config(['security.maintenance_key' => $this->key]);
    $wrongKey = $this->get('/maintenance/clear?key=nope');
    $noKey = $this->get('/maintenance/clear');
    $badAction = $this->get('/maintenance/drop-database?key='.$this->key);

    foreach ([$wrongKey, $noKey, $badAction] as $r) {
        $r->assertNotFound();
        expect($r->getContent())->toBe('Not Found')->and($r->headers->get('Content-Type'))->toContain('text/plain');
    }
});

it('runs the clear action with the right key (query or header) and never echoes the key', function () {
    config(['security.maintenance_key' => $this->key]);

    $r = $this->get('/maintenance/clear?key='.$this->key)->assertOk();
    expect($r->getContent())->toContain('Done: clear')->toContain('OK     optimize:clear')->not->toContain($this->key);
    expect($r->headers->get('Cache-Control'))->toContain('no-store')->and($r->headers->get('X-Robots-Tag'))->toContain('noindex');

    $this->withHeaders(['X-Maintenance-Key' => $this->key])->get('/maintenance/clear')->assertOk();
});

it('can sync the built-in roles', function () {
    config(['security.maintenance_key' => $this->key]);
    $this->get('/maintenance/roles?key='.$this->key)->assertOk()->assertSee('fc:sync-roles');
    expect(\App\Models\Role::whereNull('tenant_id')->count())->toBe(8);
});

it('locks the caller out after repeated wrong keys, even for the right key', function () {
    config(['security.maintenance_key' => $this->key]);
    foreach (range(1, 5) as $i) {
        $this->get('/maintenance/clear?key=wrong'.$i)->assertNotFound();
    }
    $this->get('/maintenance/clear?key='.$this->key)->assertNotFound(); // locked out
});

it('only exposes a fixed set of commands and passes nothing from the request to Artisan', function () {
    expect(array_keys(MaintenanceController::ACTIONS))->toBe(['clear', 'optimize', 'migrate', 'roles', 'storage-link', 'courier-sync']);
    expect(MaintenanceController::ACTIONS['optimize'])->toBe(['optimize:clear' => [], 'optimize' => []]);
    expect(MaintenanceController::ACTIONS['migrate'])->toBe(['migrate' => ['--force' => true]]);
    $src = file_get_contents(app_path('Http/Controllers/MaintenanceController.php'));
    expect($src)->not->toContain('$request->input')->and($src)->not->toContain('$request->all');
});

it('does not start a session or touch the database to authenticate', function () {
    config(['security.maintenance_key' => $this->key]);
    $r = $this->get('/maintenance/clear?key='.$this->key);
    expect($r->headers->getCookies())->toBeEmpty();
});
