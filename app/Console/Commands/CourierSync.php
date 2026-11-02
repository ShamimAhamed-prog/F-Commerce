<?php

namespace App\Console\Commands;

use App\Domain\Shipping\ShipmentService;
use App\Domain\Tenancy\CurrentTenant;
use App\Models\Shipment;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Polls the courier for parcels that are not finished (status answers are cached 60 s, so every 15 min is plenty). */
class CourierSync extends Command
{
    protected $signature = 'fc:courier-sync {--limit=200}';

    protected $description = 'Refresh courier status for booked, unfinished shipments';

    public function handle(ShipmentService $service): int
    {
        $done = 0;
        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            app(CurrentTenant::class)->run($tenant, function () use ($service, &$done) {
                $rows = Shipment::with(['integration', 'order'])->where('state', 'booked')
                    ->where(fn ($q) => $q->whereNull('provider_status')->orWhereNotIn('provider_status', Shipment::FINAL))
                    ->orderBy('last_synced_at')->limit((int) $this->option('limit') - $done)->get();
                foreach ($rows as $s) {
                    try {
                        $service->sync($s);
                    } catch (\Throwable $e) {
                        report($e); // one bad parcel must not stop the rest
                    }
                    $done++;
                }
            });
            if ($done >= (int) $this->option('limit')) {
                break;
            }
        }
        $this->info("Synced {$done} shipment(s).");

        return self::SUCCESS;
    }
}
