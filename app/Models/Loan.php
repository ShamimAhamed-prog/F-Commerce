<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['principal' => 'decimal:2', 'received_date' => 'date'];
    }

    public function financialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class); }

    public function transactions(): HasMany { return $this->hasMany(LoanTransaction::class)->orderBy('id'); }

    public function repaidPrincipal(): string
    {
        return (string) ($this->transactions()->sum('principal') ?: '0.00');
    }

    public function outstanding(): string
    {
        return bcsub((string) $this->principal, bcadd($this->repaidPrincipal(), '0', 2), 2);
    }
}
