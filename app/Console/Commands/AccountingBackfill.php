<?php

namespace App\Console\Commands;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Poster;
use App\Domain\Tenancy\CurrentTenant;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Posts history that predates the ledger (stock valuation and shipped orders). Idempotent: safe to re-run. */
class AccountingBackfill extends Command
{
    protected $signature = 'fc:accounting-backfill';

    protected $description = 'Create the chart of accounts and post missing ledger entries for existing stock and shipped orders';

    public function handle(Poster $poster): int
    {
        foreach (Tenant::all() as $tenant) {
            app(CurrentTenant::class)->run($tenant, function () use ($poster, $tenant) {
                ChartOfAccounts::ensure();
                $n = 0;
                foreach (StockMovement::whereIn('type', ['opening', 'adjustment', 'return'])->orderBy('id')->get() as $m) {
                    $poster->stock($m) && $n++;
                }
                foreach (Order::whereIn('status', ['shipped', 'delivered', 'delivery_failed', 'return_requested'])->orderBy('id')->get() as $o) {
                    $poster->orderShipped($o);
                    $n++;
                }
                $this->info("{$tenant->name}: processed {$n} source records");
            });
        }

        return self::SUCCESS;
    }
}
