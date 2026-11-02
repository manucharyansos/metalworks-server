<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pmp_files', 'production_instructions')) {
            Schema::table('pmp_files', fn (Blueprint $table) => $table->json('production_instructions')->nullable());
        }
    }

    public function down(): void
    {
        // Keep file instructions and task snapshots when rolling back code.
    }
};
