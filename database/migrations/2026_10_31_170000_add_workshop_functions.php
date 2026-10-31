<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extra work a workshop can do on a part (engraving, deburring, threading…).
// Engineers tick them per file; each task job keeps the names it was given.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('workshop_functions')) Schema::create('workshop_functions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('factory_id')->index();
            $t->string('name', 150);
            $t->unsignedInteger('position')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['factory_id', 'name']);
        });
        if (!Schema::hasColumn('pmp_files', 'production_function_ids')) Schema::table('pmp_files', fn (Blueprint $t) => $t->json('production_function_ids')->nullable());
        if (!Schema::hasColumn('factory_order_files', 'production_functions')) Schema::table('factory_order_files', fn (Blueprint $t) => $t->json('production_functions')->nullable());
    }

    public function down(): void
    {
        // Chosen functions are part of production history and are kept.
    }
};
