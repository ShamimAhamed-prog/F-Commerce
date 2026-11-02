<?php

namespace App\Domain\Accounting;

use App\Domain\Audit\Audit;
use App\Domain\Tenancy\CurrentTenant;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PeriodLock;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the general ledger (spec §6.1).
 *  - every entry balances (debits = credits), amounts are DECIMAL strings, never floats
 *  - posted entries are immutable; corrections are reversals that keep the original
 *  - one entry per business event (source_key) => safe to retry
 *  - nothing can be posted into a closed period
 */
class Ledger
{
    /** Today in the tenant's timezone (dates are stored as plain dates). */
    public static function today(): string
    {
        return Carbon::now(app(CurrentTenant::class)->get()?->timezone ?? 'Asia/Dhaka')->toDateString();
    }

    public static function lockedThrough(): ?string
    {
        $v = PeriodLock::max('locked_through');

        return $v ? substr((string) $v, 0, 10) : null; // sqlite stores 'Y-m-d 00:00:00'; MySQL a plain date
    }

    /**
     * @param array{date?:string,memo:string,lines:array<int,array{account:string,debit?:string|int|float,credit?:string|int|float,description?:?string}>,source_type?:string,source_key?:string,source_id?:int,reverses?:int} $spec
     */
    public function post(array $spec): JournalEntry
    {
        ChartOfAccounts::ensure();

        if (! empty($spec['source_key']) && ($existing = JournalEntry::where('source_key', $spec['source_key'])->first())) {
            return $existing; // idempotent
        }

        $date = $spec['date'] ?? self::today();
        if (($locked = self::lockedThrough()) && $date <= $locked) {
            throw new AccountingException("The period through {$locked} is closed. Entries dated {$date} cannot be posted.");
        }

        $lines = $this->normaliseLines($spec['lines']);

        return DB::transaction(function () use ($spec, $date, $lines) {
            Tenant::whereKey(app(CurrentTenant::class)->id())->lockForUpdate()->first(); // serialise numbering
            if (! empty($spec['source_key']) && ($existing = JournalEntry::where('source_key', $spec['source_key'])->first())) {
                return $existing;
            }
            $seq = (int) JournalEntry::max('seq') + 1;
            $entry = JournalEntry::create([
                'seq' => $seq, 'entry_number' => 'JE-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                'transaction_date' => $date, 'posting_date' => self::today(), 'memo' => mb_substr($spec['memo'], 0, 300),
                'source_type' => $spec['source_type'] ?? 'manual', 'source_key' => $spec['source_key'] ?? null, 'source_id' => $spec['source_id'] ?? null,
                'reverses_entry_id' => $spec['reverses'] ?? null, 'created_by' => auth()->id(),
            ]);
            foreach ($lines as $l) {
                $entry->lines()->create($l);
            }

            return $entry;
        });
    }

    /** @return array<int,array{account_id:int,debit:string,credit:string,description:?string}> */
    private function normaliseLines(array $raw): array
    {
        $codes = array_unique(array_column($raw, 'account'));
        $accounts = Account::whereIn('code', $codes)->get()->keyBy('code');
        $out = [];
        $dr = $cr = '0.00';

        foreach ($raw as $l) {
            $acct = $accounts[$l['account']] ?? throw new AccountingException("Unknown account {$l['account']}.");
            if (! $acct->is_active) {
                throw new AccountingException("Account {$acct->code} is inactive.");
            }
            $d = $this->money($l['debit'] ?? 0);
            $c = $this->money($l['credit'] ?? 0);
            if ((bccomp($d, '0', 2) > 0) === (bccomp($c, '0', 2) > 0)) {
                throw new AccountingException('Each line must be either a debit or a credit.');
            }
            $dr = bcadd($dr, $d, 2);
            $cr = bcadd($cr, $c, 2);
            $out[] = ['account_id' => $acct->id, 'debit' => $d, 'credit' => $c, 'description' => $l['description'] ?? null];
        }

        if (count($out) < 2) {
            throw new AccountingException('An entry needs at least two lines.');
        }
        if (bccomp($dr, $cr, 2) !== 0) {
            throw new AccountingException("Entry does not balance (debits {$dr}, credits {$cr}).");
        }

        return $out;
    }

    /** Accepts up to 2 decimals only; anything finer is a caller bug, not something to round away. */
    private function money(string|int|float $v): string
    {
        $s = is_float($v) ? number_format($v, 2, '.', '') : (string) $v;
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
            throw new AccountingException("Invalid amount {$s}.");
        }

        return bcadd($s, '0', 2);
    }

    /** Post the mirror image of an entry. Original stays untouched; a second reversal is a no-op. */
    public function reverse(JournalEntry $entry, string $memo, ?string $date = null): JournalEntry
    {
        $lines = $entry->lines()->get()->map(fn ($l) => [
            'account' => $l->account->code, 'debit' => (string) $l->credit, 'credit' => (string) $l->debit, 'description' => $l->description,
        ])->all();
        $lines = array_map(fn ($l) => ['account' => $l['account'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'description' => $l['description']], $lines);

        $rev = $this->post([
            'date' => $date, 'memo' => $memo, 'lines' => $lines, 'source_type' => 'reversal',
            'source_key' => "reversal:{$entry->id}", 'reverses' => $entry->id,
        ]);
        Audit::record('accounting.reversed', $entry, new: ['reversal_entry' => $rev->entry_number]);

        return $rev;
    }

    public function isReversed(JournalEntry $entry): bool
    {
        return JournalEntry::where('reverses_entry_id', $entry->id)->exists();
    }

    public function closePeriod(string $through): PeriodLock
    {
        if ($through > self::today()) {
            throw new AccountingException('You can only close periods up to today.');
        }
        if (($cur = self::lockedThrough()) && $through <= $cur) {
            throw new AccountingException("The period is already closed through {$cur}.");
        }
        $lock = PeriodLock::create(['locked_through' => $through, 'created_by' => auth()->id()]);
        Audit::record('accounting.period_closed', $lock, new: ['locked_through' => $through]);

        return $lock;
    }
}
