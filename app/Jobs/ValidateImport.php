<?php

namespace App\Jobs;

use App\Domain\Imports\ImportType;
use App\Jobs\Concerns\RunsInTenant;
use App\Models\Import;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ValidateImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenant;

    public int $timeout = 300;

    public function __construct(public int $importId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $this->inTenant(function () {
            $import = Import::find($this->importId);
            if ($import && $import->status === 'preview_ready') {
                ImportType::processor($import->type)->validate($import);
            }
        });
    }
}
