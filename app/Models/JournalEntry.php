<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'posting_date' => 'date', 'created_at' => 'datetime'];
    }

    /** Posted entries are final (spec §6.1): no edits, no deletes. Corrections are reversals. */
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Posted journal entries are immutable.'));
        static::deleting(fn () => throw new \LogicException('Posted journal entries cannot be deleted.'));
    }

    public function lines(): HasMany { return $this->hasMany(JournalEntryLine::class)->orderBy('id'); }
}
