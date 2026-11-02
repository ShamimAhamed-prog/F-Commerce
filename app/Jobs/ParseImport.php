<?php

namespace App\Jobs;

use App\Domain\Imports\ImageColumnClassifier;
use App\Domain\Imports\ImportType;
use App\Domain\Imports\SheetReader;
use App\Jobs\Concerns\RunsInTenant;
use App\Models\Import;
use App\Models\ImportMapping;
use App\Models\ImportRow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/** Step 4: parse asynchronously, store raw rows, suggest a mapping. Never fetches anything. */
class ParseImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenant;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $importId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $this->inTenant(function () {
            $import = Import::find($this->importId);
            if (! $import || $import->status !== 'uploaded') {
                return;
            }
            $import->update(['status' => 'parsing']);

            $max = config('imports.max_rows');
            $count = 0;
            $buffer = [];
            $flush = function () use (&$buffer) {
                if ($buffer) {
                    ImportRow::insert($buffer);
                    $buffer = [];
                }
            };

            try {
                $reader = new SheetReader(function (int $line, array $raw) use (&$count, &$buffer, $import, $max, $flush) {
                    if (++$count > $max) {
                        throw new \OverflowException("This file has more than {$max} rows, the limit for your plan.");
                    }
                    // IMG-I2: image-like columns are dropped here; their values are never stored or fetched.
                    $raw = array_filter($raw, fn ($h) => ! ImageColumnClassifier::looksLikeImage((string) $h), ARRAY_FILTER_USE_KEY);
                    $buffer[] = ['import_id' => $import->id, 'row_number' => $line, 'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE), 'status' => 'pending'];
                    if (count($buffer) >= 500) {
                        $flush();
                    }
                }, config('imports.chunk_size'));

                Excel::import($reader, $import->path, config('imports.disk'));
                $flush();

                if (! $reader->headers) {
                    throw new \UnexpectedValueException('The file has no header row.');
                }
                if ($count === 0) {
                    throw new \UnexpectedValueException('The file has no data rows.');
                }
            } catch (\OverflowException|\UnexpectedValueException $e) {
                $this->fail409($import, $e->getMessage());

                return;
            } catch (\Throwable $e) {
                report($e);
                $this->fail409($import, 'The file could not be read. Check that it is a valid CSV or XLSX.');

                return;
            }

            $headers = $reader->headers;
            $ignored = array_values(array_filter($headers, [ImageColumnClassifier::class, 'looksLikeImage']));
            $saved = ImportMapping::where('type', $import->type)->value('mapping') ?? [];
            $schema = ImportType::schema($import->type);
            $mapping = $schema::autoMap($headers);
            foreach ($headers as $h) { // saved mapping wins for headers it knows
                if (! in_array($h, $ignored, true) && array_key_exists($h, $saved) && ($saved[$h] === null || isset($schema::FIELDS[$saved[$h]]))) {
                    $mapping[$h] = $saved[$h];
                }
            }

            $import->update([
                'headers' => $headers,
                'ignored_columns' => $ignored,
                'mapping' => $mapping,
                'total_rows' => $count,
                'status' => 'preview_ready',
            ]);
        });
    }

    private function fail409(Import $import, string $msg): void
    {
        $import->rows()->delete();
        $import->update(['status' => 'failed', 'error' => $msg]);
    }
}
