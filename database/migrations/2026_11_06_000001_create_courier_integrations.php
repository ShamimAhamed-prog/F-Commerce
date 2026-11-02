<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_integrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 30);
            $t->string('display_name', 100);
            $t->text('credentials');            // encrypted at rest (Laravel encrypted:array cast)
            $t->json('config')->nullable();     // non-secret settings
            $t->string('status', 12)->default('pending'); // pending | connected | failed | disabled
            $t->timestamp('last_tested_at')->nullable();
            $t->string('last_error_code', 40)->nullable();
            $t->foreignId('created_by')->nullable();
            $t->foreignId('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_integrations');
    }
};
