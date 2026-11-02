<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanTransaction extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['principal' => 'decimal:2', 'interest' => 'decimal:2', 'tx_date' => 'date'];
    }

    public function loan(): BelongsTo { return $this->belongsTo(Loan::class); }

    public function financialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class); }
}
