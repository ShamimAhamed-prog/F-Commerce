<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('courier_integration_id')->nullable()->constrained('courier_integrations')->nullOnDelete();
            $t->string('provider', 30);
            $t->unsignedSmallInteger('attempt')->default(1);
            $t->string('invoice', 100);                 // our client reference: the provider refuses duplicate invoices
            $t->string('state', 20)->default('booking'); // booking | booked | booking_unknown | failed
            $t->string('consignment_id', 60)->nullable();
            $t->string('tracking_code', 60)->nullable();
            $t->string('provider_status', 60)->nullable(); // raw provider status (pending, in_review, delivered, ...)
            $t->decimal('cod_amount', 18, 2)->default(0);
            $t->string('error_code', 40)->nullable();
            $t->string('message', 300)->nullable();       // safe text only, never a raw provider body
            $t->timestamp('booked_at')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'provider', 'invoice']);
            $t->index(['tenant_id', 'order_id']);
            $t->index(['tenant_id', 'state', 'provider_status']);
        });

        Schema::create('shipment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $t->string('status', 60);
            $t->string('note', 300)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
    }
};
