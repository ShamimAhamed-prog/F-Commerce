<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['expenses', 'capital_contributions', 'owner_drawings', 'cod_settlements'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->string('idempotency_key', 64)->nullable();
                $t->unique(['tenant_id', 'idempotency_key'], "{$table}_idem_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (['expenses', 'capital_contributions', 'owner_drawings', 'cod_settlements'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique("{$table}_idem_unique");
                $t->dropColumn('idempotency_key');
            });
        }
    }
};
