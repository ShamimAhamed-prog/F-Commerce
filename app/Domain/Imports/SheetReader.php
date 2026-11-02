<?php

namespace App\Domain\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;

/**
 * Streams a CSV/XLSX in chunks. First non-empty row is the header row.
 * Cells are inert text: nothing here evaluates formulas or touches the network.
 */
class SheetReader implements ToCollection, WithChunkReading
{
    public array $headers = [];

    private int $line = 0;

    /** @param callable(int,array):void $onRow  row_number (1-based sheet line), header=>value */
    public function __construct(private $onRow, private int $chunk = 500) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $this->line++;
            $cells = array_map([$this, 'cell'], $row->toArray());

            if (! $this->headers) {
                if (array_filter($cells, fn ($c) => $c !== '')) {
                    $this->headers = $this->uniqueHeaders($cells);
                }

                continue;
            }
            if (! array_filter($cells, fn ($c) => $c !== '')) {
                continue; // blank row
            }
            $assoc = [];
            foreach ($this->headers as $i => $h) {
                $assoc[$h] = $cells[$i] ?? '';
            }
            ($this->onRow)($this->line, $assoc);
        }
    }

    public function chunkSize(): int
    {
        return $this->chunk;
    }

    private function cell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v)) {
            return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        // Normalise to plain text. A leading BOM on the first header cell is removed.
        return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $v));
    }

    private function uniqueHeaders(array $cells): array
    {
        // Trailing empty header cells are dropped; duplicate/empty names get a suffix.
        while ($cells && end($cells) === '') {
            array_pop($cells);
        }
        $seen = [];
        foreach ($cells as $i => $c) {
            $name = $c === '' ? 'Column '.($i + 1) : $c;
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            $cells[$i] = $seen[$name] > 1 ? "{$name} ({$seen[$name]})" : $name;
        }

        return $cells;
    }
}
