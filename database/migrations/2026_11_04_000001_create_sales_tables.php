<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('phone', 32);            // as entered (original preserved)
            $t->string('phone_normalized', 20); // 01XXXXXXXXX
            $t->string('alt_phone', 32)->nullable();
            $t->text('address')->nullable();    // latest delivery address
            $t->string('area')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'phone_normalized']);
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('seq');
            $t->string('order_number', 30);
            $t->string('source', 20)->default('manual'); // manual | import
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('customer_name');
            $t->string('phone', 32);
            $t->text('address');
            $t->string('area')->nullable();
            $t->decimal('items_subtotal', 18, 2)->default(0);
            $t->decimal('item_discount', 18, 2)->default(0);
            $t->decimal('order_discount', 18, 2)->default(0);
            $t->decimal('shipping_charge', 18, 2)->default(0);
            $t->decimal('other_charges', 18, 2)->default(0);
            $t->decimal('tax_amount', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);
            $t->decimal('advance', 18, 2)->default(0);
            $t->decimal('cod_amount', 18, 2)->default(0);
            $t->string('status', 24)->default('pending_confirmation');
            $t->string('stock_state', 10)->default('none'); // none | reserved | deducted
            $t->text('notes')->nullable();
            $t->string('external_ref', 100)->nullable();
            $t->string('idempotency_key', 64)->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'seq']);
            $t->unique(['tenant_id', 'order_number']);
            $t->unique(['tenant_id', 'external_ref']);
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'status', 'created_at']);
            $t->index(['tenant_id', 'phone']);
        });

        // Snapshot of what was agreed: later product price/name changes never rewrite history.
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $t->string('sku', 100);
            $t->string('name');
            $t->unsignedInteger('qty');
            $t->decimal('unit_price', 18, 2);
            $t->decimal('discount', 18, 2)->default(0); // line discount (absolute)
            $t->decimal('line_total', 18, 2);
        });

        Schema::create('order_status_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('old_status', 24)->nullable();
            $t->string('new_status', 24);
            $t->foreignId('actor_id')->nullable();
            $t->string('note', 300)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['order_status_histories', 'order_items', 'orders', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
