<?php

it('serves a root manifest by default', function () {
    config(['deployment.base_path' => '']);
    $m = $this->get('/manifest.webmanifest')->assertOk()->json();
    expect($m['scope'])->toBe('/')
        ->and($m['start_url'])->toBe('/app/dashboard?source=pwa')
        ->and($m['icons'][0]['src'])->toBe('/icons/icon-192.png');
});

it('follows APP_BASE_PATH for every URL in the manifest', function () {
    config(['deployment.base_path' => '/fcom-manager']);
    $m = $this->get('/manifest.webmanifest')->assertOk()->json();
    expect($m['id'])->toBe('/fcom-manager/app/')
        ->and($m['scope'])->toBe('/fcom-manager/')
        ->and($m['start_url'])->toBe('/fcom-manager/app/dashboard?source=pwa')
        ->and($m['shortcuts'][0]['url'])->toStartWith('/fcom-manager/')
        ->and(collect($m['icons'])->every(fn ($i) => str_starts_with($i['src'], '/fcom-manager/icons/')))->toBeTrue();
});

it('normalises the configured base path', function () {
    foreach (['fcom-manager' => '/fcom-manager', '/fcom-manager/' => '/fcom-manager', '' => '', '/' => ''] as $raw => $want) {
        putenv("APP_BASE_PATH=$raw");
        $_ENV['APP_BASE_PATH'] = $raw;
        expect((require base_path('config/deployment.php'))['base_path'])->toBe($want);
    }
    putenv('APP_BASE_PATH'); unset($_ENV['APP_BASE_PATH']);
});
