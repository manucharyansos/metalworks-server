<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the legacy predecessor for old clients; a JSON list supports joins.
        if (!Schema::hasColumn('factory_orders', 'predecessor_ids')) Schema::table('factory_orders', fn (Blueprint $table) => $table->json('predecessor_ids')->nullable());
        if (!Schema::hasColumn('factory_orders', 'route_position')) Schema::table('factory_orders', fn (Blueprint $table) => $table->unsignedInteger('route_position')->default(0));
        if (!Schema::hasColumn('factory_orders', 'ready_at')) Schema::table('factory_orders', fn (Blueprint $table) => $table->timestamp('ready_at')->nullable());
    }

    public function down(): void
    {
        foreach (['predecessor_ids', 'route_position', 'ready_at'] as $column) {
            if (Schema::hasColumn('factory_orders', $column)) Schema::table('factory_orders', fn (Blueprint $table) => $table->dropColumn($column));
        }
    }
};
