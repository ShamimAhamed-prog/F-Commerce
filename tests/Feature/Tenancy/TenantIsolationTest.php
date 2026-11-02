<?php

use App\Domain\Tenancy\BelongsToTenant;
use App\Domain\Tenancy\CurrentTenant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class IsolationProbe extends Model
{
    use BelongsToTenant;

    protected $table = 'isolation_probes';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('isolation_probes', function (Blueprint $t) {
        $t->id();
        $t->foreignId('tenant_id');
        $t->string('label');
        $t->timestamps();
    });
    $this->a = Tenant::factory()->create();
    $this->b = Tenant::factory()->create();
});

it('scopes reads to the active tenant', function () {
    asTenant($this->a);
    IsolationProbe::create(['label' => 'a-row']);
    asTenant($this->b);
    IsolationProbe::create(['label' => 'b-row']);

    expect(IsolationProbe::pluck('label')->all())->toBe(['b-row']);
    asTenant($this->a);
    expect(IsolationProbe::pluck('label')->all())->toBe(['a-row']);
});

it('fails closed with no active tenant', function () {
    asTenant($this->a);
    IsolationProbe::create(['label' => 'a-row']);
    app(CurrentTenant::class)->forget();

    expect(IsolationProbe::count())->toBe(0);
});

it('refuses to create without a tenant', function () {
    IsolationProbe::create(['label' => 'x']);
})->throws(LogicException::class);

it('blocks a cross-tenant write', function () {
    asTenant($this->a);
    IsolationProbe::create(['label' => 'x', 'tenant_id' => $this->b->id]);
})->throws(LogicException::class, 'Cross-tenant');

it('keeps tenant_id immutable', function () {
    asTenant($this->a);
    $row = IsolationProbe::create(['label' => 'x']);
    $row->tenant_id = $this->b->id;
    $row->save();
})->throws(LogicException::class, 'immutable');

it('cannot load another tenant row by id', function () {
    asTenant($this->a);
    $row = IsolationProbe::create(['label' => 'secret']);
    asTenant($this->b);

    expect(IsolationProbe::find($row->id))->toBeNull();
});

it('restores the previous context after run()', function () {
    asTenant($this->a);
    app(CurrentTenant::class)->run($this->b, fn () => expect(app(CurrentTenant::class)->id())->toBe($this->b->id));

    expect(app(CurrentTenant::class)->id())->toBe($this->a->id);
});
