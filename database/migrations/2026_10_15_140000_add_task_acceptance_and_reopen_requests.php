<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('task_change_requests', 'requested_status')) {
            Schema::table('task_change_requests', fn (Blueprint $t) => $t->string('requested_status', 20)->nullable());
        }
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (!DB::table('factory_order_statuses')->where('company_id', $companyId)->where('value', 'accepted')->exists()) {
                DB::table('factory_order_statuses')->insert(['company_id' => $companyId, 'key' => 'acceptance',
                    'name' => 'Ընդունել', 'status_label' => 'Ընդունված', 'value' => 'accepted', 'icon' => 'Check',
                    'color' => '#6366F1', 'requires_reason' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('factory_order_statuses')->where('value', 'confirmed')->where('name', 'Կատարել')->update(['name' => 'Սկսել']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_change_requests', 'requested_status')) Schema::table('task_change_requests', fn (Blueprint $t) => $t->dropColumn('requested_status'));
        // Accepted jobs and their status definitions are retained as production history.
    }
};
