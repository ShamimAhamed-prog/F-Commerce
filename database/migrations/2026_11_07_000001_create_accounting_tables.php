<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 10);
            $t->string('name');
            $t->string('type', 10); // asset | liability | equity | income | cogs | expense
            $t->boolean('is_system')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        // Posted entries are final: no updated_at, never edited or deleted. Corrections are reversals.
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('seq');
            $t->string('entry_number', 20);
            $t->date('transaction_date');
            $t->date('posting_date');
            $t->string('memo', 300);
            $t->string('source_type', 40)->nullable(); // order_revenue | order_cogs | expense | capital | drawing | cod_settlement | stock | reversal | manual
            $t->string('source_key', 100)->nullable();  // idempotency: one entry per business event
            $t->unsignedBigInteger('source_id')->nullable();
            $t->foreignId('reverses_entry_id')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'seq']);
            $t->unique(['tenant_id', 'source_key']);
            $t->index(['tenant_id', 'transaction_date']);
        });

        Schema::create('journal_entry_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->decimal('debit', 18, 2)->default(0);
            $t->decimal('credit', 18, 2)->default(0);
            $t->string('description', 200)->nullable();
            $t->index(['tenant_id', 'account_id']);
        });

        Schema::create('financial_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->string('name');
            $t->string('type', 10); // cash | bank | mfs
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'account_id']);
        });

        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->date('expense_date');
            $t->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $t->decimal('amount', 18, 2);
            $t->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $t->string('vendor')->nullable();
            $t->string('reference', 100)->nullable();
            $t->string('description', 500)->nullable();
            $t->string('status', 8)->default('paid'); // paid | void
            $t->foreignId('entry_id')->nullable();
            $t->foreignId('void_entry_id')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'expense_date']);
        });

        foreach (['capital_contributions' => 'contribution_date', 'owner_drawings' => 'drawing_date'] as $table => $dateCol) {
            Schema::create($table, function (Blueprint $t) use ($dateCol) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->date($dateCol);
                $t->decimal('amount', 18, 2);
                $t->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
                $t->string('note', 300)->nullable();
                $t->foreignId('entry_id')->nullable();
                $t->foreignId('created_by')->nullable();
                $t->timestamps();
            });
        }

        // COD money arrives separately from delivery ("delivered" is not "COD paid").
        Schema::create('cod_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->date('settled_date');
            $t->decimal('received', 18, 2);
            $t->decimal('fee', 18, 2)->default(0);
            $t->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $t->string('reference', 100)->nullable();
            $t->foreignId('entry_id')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('period_locks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->date('locked_through');
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['period_locks', 'cod_settlements', 'owner_drawings', 'capital_contributions', 'expenses', 'expense_categories', 'financial_accounts', 'journal_entry_lines', 'journal_entries', 'accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
