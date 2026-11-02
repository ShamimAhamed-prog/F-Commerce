<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable ledger. No updated_at, never soft-deleted.
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $t->string('type', 20); // opening|purchase|sale|reservation|release|return|adjustment|transfer
            $t->integer('qty'); // signed: +in / -out
            $t->decimal('unit_cost', 18, 4)->default(0);
            $t->string('source_type', 60)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('reason', 200)->nullable();
            $t->foreignId('actor_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'product_id', 'created_at']);
        });

        // Cache derived from the ledger. variant_id 0 = product has no variants.
        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('variant_id')->default(0);
            $t->integer('on_hand')->default(0);
            $t->integer('reserved')->default(0);
            $t->decimal('avg_cost', 18, 4)->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'product_id', 'variant_id']);
        });

        Schema::create('imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('type', 30); // products
            $t->string('status', 24)->default('uploaded');
            $t->string('original_name');
            $t->string('path');
            $t->json('headers')->nullable();
            $t->json('ignored_columns')->nullable();
            $t->json('mapping')->nullable();
            $t->json('options')->nullable(); // {duplicate_policy}
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('created_count')->default(0);
            $t->unsignedInteger('updated_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->string('error', 300)->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'type', 'created_at']);
        });

        Schema::create('import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('row_number');
            $t->json('raw_data');
            $t->json('normalized_data')->nullable();
            $t->string('status', 12)->default('pending'); // pending|valid|invalid|created|updated|skipped|failed
            $t->json('errors')->nullable();
            $t->string('record_type', 40)->nullable();
            $t->unsignedBigInteger('record_id')->nullable();
            $t->index(['import_id', 'status']);
        });

        Schema::create('import_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('type', 30);
            $t->json('mapping');
            $t->timestamps();
            $t->unique(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['import_mappings', 'import_rows', 'imports', 'stock_balances', 'stock_movements'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
