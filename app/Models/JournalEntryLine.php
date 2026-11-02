<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntryLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Journal lines are immutable.'));
        static::deleting(fn () => throw new \LogicException('Journal lines cannot be deleted.'));
    }

    public function account(): BelongsTo { return $this->belongsTo(Account::class); }

    public function entry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
}
