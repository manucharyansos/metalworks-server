<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The day planned for a workshop stage to begin, chosen from an operator's free time.
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('factory_orders', 'planned_start'))
            Schema::table('factory_orders', fn (Blueprint $table) => $table->date('planned_start')->nullable());
    }
    public function down(): void {
        if (Schema::hasColumn('factory_orders', 'planned_start'))
            Schema::table('factory_orders', fn (Blueprint $table) => $table->dropColumn('planned_start'));
    }
};
