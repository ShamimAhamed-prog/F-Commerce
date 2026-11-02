<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\AccountingException;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\Poster;
use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Gate::allows('expenses.view'), 403);
        ChartOfAccounts::ensure();
        $q = Expense::with(['category:id,name', 'financialAccount:id,name'])
            ->when($request->input('category'), fn ($q, $c) => $q->where('expense_category_id', (int) $c))
            ->orderByDesc('expense_date')->orderByDesc('id');
        $page = $q->paginate(25)->withQueryString();

        return Inertia::render('Accounting/Expenses', [
            'expenses' => $page->through(fn ($e) => [
                'id' => $e->id, 'date' => $e->expense_date->toDateString(), 'category' => $e->category->name, 'amount' => (string) $e->amount,
                'account' => $e->financialAccount->name, 'vendor' => $e->vendor, 'reference' => $e->reference, 'description' => $e->description, 'status' => $e->status, 'has_receipt' => (bool) $e->receipt_path,
            ]),
            'month_total' => (string) (Expense::where('status', 'paid')->where('expense_date', '>=', substr(Ledger::today(), 0, 8).'01')->sum('amount') ?: '0.00'),
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'financial' => FinancialAccount::where('is_active', true)->get(['id', 'name', 'type']),
            'filters' => $request->only('category'),
            'today' => Ledger::today(),
            'canManage' => Gate::allows('expenses.manage'),
        ]);
    }

    public function store(Request $request, Poster $poster): RedirectResponse
    {
        abort_unless(Gate::allows('expenses.manage'), 403);
        ChartOfAccounts::ensure();
        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expense_category_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999'],
            'financial_account_id' => ['required', 'integer'],
            'vendor' => ['nullable', 'string', 'max:150'], 'reference' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'],
            'receipt' => ['nullable', 'file', 'max:5120'],
        ]);

        if ($existing = Expense::where('idempotency_key', $d['idempotency_key'])->first()) {
            return back()->with('success', __('Expense already saved.')); // replay of the same submit: no duplicate
        }
        ExpenseCategory::findOrFail($d['expense_category_id']);
        FinancialAccount::where('is_active', true)->findOrFail($d['financial_account_id']);

        // Receipt photo / PDF: validated by CONTENT, stored privately under a random name (never the client's).
        $receipt = $request->file('receipt') ? $this->storeReceipt($request->file('receipt')) : [null, null];

        try {
            DB::transaction(function () use ($d, $poster, $request, $receipt) {
                $e = Expense::create(collect($d)->except('receipt')->all() + ['status' => 'paid', 'created_by' => $request->user()->id, 'receipt_path' => $receipt[0], 'receipt_mime' => $receipt[1]]);
                $e->update(['entry_id' => $poster->expense($e->load(['category.account', 'financialAccount.account']))->id]);
                Audit::record('expense.created', $e, new: ['amount' => $e->amount, 'category' => $e->category->name]);
            });
        } catch (AccountingException $e) {
            if ($receipt[0]) {
                Storage::disk(config('catalog.disk'))->delete($receipt[0]);
            }
            throw ValidationException::withMessages(['expense_date' => $e->getMessage()]);
        }

        return back()->with('success', __('Expense saved.'));
    }

    /** @return array{0:string,1:string} */
    private function storeReceipt(\Illuminate\Http\UploadedFile $file): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
        if (! $ext || ($mime !== 'application/pdf' && ! @getimagesize($file->getRealPath()))) {
            throw ValidationException::withMessages(['receipt' => 'The receipt must be a JPEG, PNG, WebP or PDF file.']);
        }
        $path = 'tenants/'.app(\App\Domain\Tenancy\CurrentTenant::class)->id().'/receipts/'.bin2hex(random_bytes(16)).'.'.$ext;
        Storage::disk(config('catalog.disk'))->put($path, file_get_contents($file->getRealPath()));

        return [$path, $mime];
    }

    public function receipt(Expense $expense)
    {
        abort_unless(Gate::allows('expenses.view') && $expense->receipt_path, 403);
        abort_unless(Storage::disk(config('catalog.disk'))->exists($expense->receipt_path), 404);

        return Storage::disk(config('catalog.disk'))->response($expense->receipt_path, null, [
            'Content-Type' => $expense->receipt_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /** Voiding reverses the entry; the original is kept for the audit trail. */
    public function void(Expense $expense, Ledger $ledger): RedirectResponse
    {
        abort_unless(Gate::allows('expenses.manage'), 403);
        abort_if($expense->status === 'void', 409, 'Already void.');
        try {
            DB::transaction(function () use ($expense, $ledger) {
                $rev = $ledger->reverse(\App\Models\JournalEntry::findOrFail($expense->entry_id), "Void expense #{$expense->id}");
                $expense->update(['status' => 'void', 'void_entry_id' => $rev->id]);
                Audit::record('expense.voided', $expense);
            });
        } catch (AccountingException $e) {
            throw ValidationException::withMessages(['expense' => $e->getMessage()]);
        }

        return back()->with('success', __('Expense voided.'));
    }
}
