<?php

namespace App\Jobs;

use App\Domain\Imports\ImportType;
use App\Jobs\Concerns\RunsInTenant;
use App\Models\Import;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RunImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenant;

    public int $timeout = 900;

    public int $tries = 1; // rows are idempotent by status, retries are explicit (retry endpoint)

    public function __construct(public int $importId, public bool $onlyFailed = false)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $this->inTenant(function () {
            $import = Import::find($this->importId);
            if ($import && $import->status === 'processing') {
                ImportType::processor($import->type)->run($import, $this->onlyFailed);
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        $this->inTenant(fn () => Import::where('id', $this->importId)->update(['status' => 'failed', 'error' => 'The import stopped unexpectedly.']));
    }
}
