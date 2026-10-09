<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('factory_orders', 'depends_on_id')) {
            Schema::table('factory_orders', fn (Blueprint $table) => $table->unsignedBigInteger('depends_on_id')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('factory_orders', fn (Blueprint $table) => $table->dropColumn('depends_on_id'));
    }
};
