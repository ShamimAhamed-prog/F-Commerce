<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('lender', 150);
            $t->decimal('principal', 18, 2);
            $t->date('received_date');
            $t->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $t->string('note', 300)->nullable();
            $t->foreignId('entry_id')->nullable();
            $t->string('idempotency_key', 64)->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::create('loan_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_id')->constrained()->restrictOnDelete();
            $t->date('tx_date');
            $t->decimal('principal', 18, 2)->default(0); // repaid principal reduces the liability
            $t->decimal('interest', 18, 2)->default(0);  // interest is an expense
            $t->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $t->string('note', 300)->nullable();
            $t->foreignId('entry_id')->nullable();
            $t->string('idempotency_key', 64)->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::table('expenses', function (Blueprint $t) {
            $t->string('receipt_path')->nullable();
            $t->string('receipt_mime', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', fn (Blueprint $t) => $t->dropColumn(['receipt_path', 'receipt_mime']));
        Schema::dropIfExists('loan_transactions');
        Schema::dropIfExists('loans');
    }
};
