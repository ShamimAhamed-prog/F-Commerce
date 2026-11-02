<?php

use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\StockService;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;

beforeEach(function () {
    [$this->user, $this->tenant] = memberOf();
    asTenant($this->tenant);
    $this->actingAs($this->user);
    $this->product = Product::factory()->create();
    $this->stock = app(StockService::class);
});

it('values stock with weighted average cost', function () {
    $this->stock->record($this->product->id, null, 'opening', 10, '100.00');
    $this->stock->record($this->product->id, null, 'purchase', 10, '200.00');

    $b = StockBalance::first();
    expect($b->on_hand)->toBe(20)->and((string) $b->avg_cost)->toBe('150.0000');
});

it('weighted average handles fractions without float drift', function () {
    expect($this->stock->newAverage(3, '10.0000', 3, '10.3333'))->toBe('10.1666');
    expect($this->stock->newAverage(0, '0.0000', 5, '99.99'))->toBe('99.9900');
});

it('values sales at the current average and leaves the average unchanged', function () {
    $this->stock->record($this->product->id, null, 'opening', 10, '100.00');
    $this->stock->record($this->product->id, null, 'purchase', 10, '200.00');
    $sale = $this->stock->record($this->product->id, null, 'sale', -5);

    expect((string) $sale->unit_cost)->toBe('150.0000')
        ->and((string) StockBalance::first()->avg_cost)->toBe('150.0000')
        ->and(StockBalance::first()->on_hand)->toBe(15);
});

it('never oversells', function () {
    $this->stock->record($this->product->id, null, 'opening', 3, '10');
    $this->stock->record($this->product->id, null, 'sale', -4);
})->throws(InsufficientStock::class);

it('keeps the balance equal to the ledger', function () {
    foreach ([['opening', 10], ['purchase', 5], ['sale', -4], ['adjustment', -2], ['adjustment', 3], ['return', 1]] as [$t, $q]) {
        $this->stock->record($this->product->id, null, $t, $q, '50');
    }

    expect(StockBalance::first()->on_hand)->toBe($this->stock->ledgerOnHand($this->product->id, null))->toBe(13);
});

it('rejects wrong-signed quantities and zero', function () {
    expect(fn () => $this->stock->record($this->product->id, null, 'sale', 2))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->stock->record($this->product->id, null, 'purchase', -2))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->stock->record($this->product->id, null, 'adjustment', 0))->toThrow(InvalidArgumentException::class);
});

it('reserves, blocks over-reservation, and consumes the reservation on dispatch', function () {
    $this->stock->record($this->product->id, null, 'opening', 10, '10');
    $this->stock->record($this->product->id, null, 'reservation', 4);
    expect($this->stock->available($this->product->id, null))->toBe(6);

    expect(fn () => $this->stock->record($this->product->id, null, 'reservation', 7))->toThrow(InsufficientStock::class);

    $this->stock->record($this->product->id, null, 'sale', -4); // dispatch
    $b = StockBalance::first();
    expect($b->on_hand)->toBe(6)->and($b->reserved)->toBe(0);

    $this->stock->record($this->product->id, null, 'reservation', 2);
    $this->stock->record($this->product->id, null, 'release', -2); // cancellation
    expect($this->stock->available($this->product->id, null))->toBe(6);
});

it('does not let adjustments cut into reserved stock', function () {
    $this->stock->record($this->product->id, null, 'opening', 10, '10');
    $this->stock->record($this->product->id, null, 'reservation', 8);
    $this->stock->record($this->product->id, null, 'adjustment', -5, null, 'damaged');
})->throws(InsufficientStock::class);

it('makes ledger rows immutable', function () {
    $m = $this->stock->record($this->product->id, null, 'opening', 1, '1');
    expect(fn () => $m->update(['qty' => 9]))->toThrow(LogicException::class);
    expect(fn () => $m->delete())->toThrow(LogicException::class);
});

it('isolates stock per tenant', function () {
    $this->stock->record($this->product->id, null, 'opening', 5, '1');
    [, $other] = memberOf();
    asTenant($other);

    expect(StockMovement::count())->toBe(0)->and(StockBalance::count())->toBe(0);
});

it('rolls back the balance when the movement fails', function () {
    $this->stock->record($this->product->id, null, 'opening', 5, '1');
    try {
        $this->stock->record($this->product->id, null, 'sale', -9);
    } catch (InsufficientStock) {
    }
    expect(StockBalance::first()->on_hand)->toBe(5)->and(StockMovement::count())->toBe(1);
});
