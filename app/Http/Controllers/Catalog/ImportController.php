<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Audit\Audit;
use App\Domain\Imports\ImageColumnClassifier;
use App\Domain\Imports\ImportType;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Jobs\ParseImport;
use App\Jobs\RunImport;
use App\Jobs\ValidateImport;
use App\Models\Import;
use App\Models\ImportMapping;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Product CSV/XLSX import: DATA ONLY (spec §5.3). */
class ImportController extends Controller
{
    private function authorizeImport(string $type = 'products'): void
    {
        abort_unless(ImportType::valid($type) && Gate::allows(ImportType::permission($type)), 403);
    }

    public function index(): Response
    {
        $types = array_values(array_filter(ImportType::types(), fn ($t) => Gate::allows(ImportType::permission($t))));
        abort_if(! $types, 403);

        return Inertia::render('Imports/Index', [
            'types' => $types,
            'imports' => Import::whereIn('type', $types)->latest('id')->limit(25)
                ->get(['id', 'type', 'original_name', 'status', 'total_rows', 'created_count', 'updated_count', 'skipped_count', 'failed_count', 'created_at']),
            'limits' => ['max_rows' => config('imports.max_rows'), 'max_file_mb' => config('imports.max_file_kb') / 1024],
        ]);
    }

    public function template(string $type = 'products'): StreamedResponse
    {
        $this->authorizeImport($type);
        $schema = ImportType::schema($type);

        return $this->csv("{$type}-import-template.csv", $schema::templateHeaders(), $schema::templateRows());
    }

    public function upload(Request $request, string $type = 'products'): RedirectResponse
    {
        $this->authorizeImport($type);
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $fail = fn (string $m) => throw ValidationException::withMessages(['file' => $m]);

        if (in_array($ext, ['zip', 'rar', '7z', 'gz', 'tar'], true)) {
            $fail('ZIP and archive files are not accepted. Upload a .csv or .xlsx with product data only; add images after import.');
        }
        if (! in_array($ext, ['csv', 'xlsx'], true)) {
            $fail('Only .csv and .xlsx files are supported.');
        }
        if ($file->getSize() > config('imports.max_file_kb') * 1024) {
            $fail('The file is larger than '.(config('imports.max_file_kb') / 1024).' MB.');
        }
        $this->checkContent($file->getRealPath(), $ext, $fail);

        $path = Storage::disk(config('imports.disk'))->putFileAs(
            'tenants/'.app(CurrentTenant::class)->id().'/imports', $file, bin2hex(random_bytes(16)).'.'.$ext,
        );

        $import = Import::create([
            'type' => $type, 'status' => 'uploaded', 'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'path' => $path, 'options' => ['duplicate_policy' => 'skip'], 'created_by' => $request->user()->id,
        ]);
        Audit::record('import.uploaded', $import, new: ['name' => $import->original_name]);
        ParseImport::dispatch($import->id);

        return redirect()->route('imports.show', $import);
    }

    private function checkContent(string $path, string $ext, callable $fail): void
    {
        if ($ext === 'xlsx') {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true || $zip->locateName('xl/workbook.xml') === false) {
                $fail('This is not a valid .xlsx file.');
            }
            $zip->close();

            return;
        }
        $head = file_get_contents($path, false, null, 0, 1024 * 1024);
        if (str_contains($head, "\0") || str_starts_with($head, 'PK')) {
            $fail('This is not a valid text CSV file.');
        }
        $head = preg_replace('/^\xEF\xBB\xBF/', '', $head);
        // Only when we read a truncated window can a multi-byte char be cut at its end; then trim up to 3 bytes.
        $truncated = filesize($path) > strlen($head) + 3;
        for ($i = 0; $truncated && $i < 4 && ! mb_check_encoding($head, 'UTF-8'); $i++) {
            $head = substr($head, 0, -1);
        }
        if (! mb_check_encoding($head, 'UTF-8')) {
            $fail('The CSV must be saved as UTF-8 (Bangla text needs UTF-8).');
        }
    }

    public function show(Import $import): Response
    {
        $this->authorizeImport($import->type);
        $o = $import->options ?? [];
        $created = $import->rows()->whereIn('status', ['created', 'updated'])->whereNotNull('record_id')->pluck('record_id')->unique();

        return Inertia::render('Imports/Show', [
            'import' => $import->only('id', 'type', 'original_name', 'status', 'headers', 'ignored_columns', 'mapping', 'total_rows',
                'created_count', 'updated_count', 'skipped_count', 'failed_count', 'error') + [
                'duplicate_policy' => $o['duplicate_policy'] ?? 'skip',
                'validated' => (bool) ($o['validated'] ?? false),
                'validating' => $import->status === 'preview_ready' && array_key_exists('validated', $o) && ! $o['validated'],
                'valid_rows' => $o['valid_rows'] ?? 0,
                'invalid_rows' => $o['invalid_rows'] ?? 0,
                'duplicate_products' => $o['duplicate_products'] ?? 0,
            ],
            'preview' => $import->headers ? $import->rows()->orderBy('row_number')->limit(10)->get()
                ->map(fn ($r) => ['row' => $r->row_number, 'cells' => $r->raw_data, 'status' => $r->status, 'errors' => $r->errors])->values() : [],
            'problems' => $import->rows()->whereIn('status', ['invalid', 'failed'])->orderBy('row_number')->limit(50)->get(['row_number', 'errors'])
                ->map(fn ($r) => ['row' => $r->row_number, 'errors' => $r->errors]),
            'fields' => collect(ImportType::schema($import->type)::FIELDS)->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0]])->values(),
            'missingImages' => $import->type !== 'products' || $created->isEmpty() ? 0 : Product::whereIn('id', $created)->doesntHave('images')->count(),
        ]);
    }

    public function mapping(Request $request, Import $import): RedirectResponse
    {
        $this->authorizeImport($import->type);
        abort_unless($import->status === 'preview_ready', 409, 'This import can no longer be edited.');

        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'duplicate_policy' => ['required', Rule::in(['skip', 'update', 'create_new_sku'])],
        ]);

        $mapping = [];
        foreach ($import->headers as $h) {
            $f = $data['mapping'][$h] ?? null;
            if ($f !== null && ! isset(ImportType::schema($import->type)::FIELDS[$f])) {
                throw ValidationException::withMessages(['mapping' => "Unknown field for column {$h}."]);
            }
            $mapping[$h] = ImageColumnClassifier::looksLikeImage($h) ? null : $f; // image-like columns can never be mapped
        }
        $mapped = array_filter($mapping);
        if (count($mapped) !== count(array_unique($mapped))) {
            throw ValidationException::withMessages(['mapping' => 'Each product field can be mapped to only one column.']);
        }
        foreach (ImportType::required($import->type) as $required) {
            if (! in_array($required, $mapped, true)) {
                throw ValidationException::withMessages(['mapping' => 'Map a column for each required field: '.implode(', ', ImportType::required($import->type)).'.']);
            }
        }

        $import->update([
            'mapping' => $mapping,
            'options' => ['duplicate_policy' => $data['duplicate_policy'], 'validated' => false],
        ]);
        $import->rows()->update(['status' => 'pending', 'errors' => null, 'normalized_data' => null]);
        ImportMapping::updateOrCreate(['type' => $import->type], ['mapping' => $mapping]);
        ValidateImport::dispatch($import->id);

        return redirect()->route('imports.show', $import);
    }

    public function commit(Import $import): RedirectResponse
    {
        $this->authorizeImport($import->type);
        $o = $import->options ?? [];
        abort_unless(($o['validated'] ?? false) && ($o['valid_rows'] ?? 0) > 0, 409, 'Validate the file and fix errors first.');

        // Atomic transition: a double-click or replay cannot start the import twice.
        $started = Import::where('id', $import->id)->where('status', 'preview_ready')->update(['status' => 'processing']);
        abort_unless($started, 409, 'This import was already started.');

        Audit::record('import.started', $import, new: ['policy' => $o['duplicate_policy'] ?? 'skip']);
        RunImport::dispatch($import->id);

        return redirect()->route('imports.show', $import);
    }

    public function retry(Import $import): RedirectResponse
    {
        $this->authorizeImport($import->type);
        $started = Import::where('id', $import->id)->where('status', 'completed_with_errors')->update(['status' => 'processing']);
        abort_unless($started, 409, 'Nothing to retry.');
        RunImport::dispatch($import->id, onlyFailed: true);

        return redirect()->route('imports.show', $import);
    }

    public function cancel(Import $import): RedirectResponse
    {
        $this->authorizeImport($import->type);
        $done = Import::where('id', $import->id)->whereIn('status', ['uploaded', 'parsing', 'preview_ready'])->update(['status' => 'cancelled']);
        abort_unless($done, 409, 'This import can no longer be cancelled.');
        Audit::record('import.cancelled', $import);

        return redirect()->route('imports.index');
    }

    public function errors(Import $import): StreamedResponse
    {
        $this->authorizeImport($import->type);
        $headers = $import->headers ?? [];
        $rows = $import->rows()->whereIn('status', ['invalid', 'failed', 'skipped'])->orderBy('row_number')->get()
            ->map(fn ($r) => array_merge([$r->row_number, implode(' | ', $r->errors ?? [])], array_map(fn ($h) => $r->raw_data[$h] ?? '', $headers)))->all();

        return $this->csv("import-{$import->id}-errors.csv", array_merge(['row_number', 'errors'], $headers), $rows);
    }

    /** CSV with formula-injection protection (spec §12). */
    private function csv(string $name, array $headers, array $rows): StreamedResponse
    {
        $safe = fn ($v) => is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;

        return response()->streamDownload(function () use ($headers, $rows, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens Bangla text correctly
            fputcsv($out, array_map($safe, $headers));
            foreach ($rows as $r) {
                fputcsv($out, array_map($safe, $r));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
