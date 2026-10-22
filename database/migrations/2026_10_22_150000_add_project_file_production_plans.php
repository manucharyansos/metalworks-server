<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pmp_files', 'factory_order_files'] as $table) {
            if (!Schema::hasColumn($table, 'dimensions')) Schema::table($table, fn (Blueprint $t) => $t->string('dimensions')->nullable());
            if (!Schema::hasColumn($table, 'inspection_required')) Schema::table($table, fn (Blueprint $t) => $t->boolean('inspection_required')->default(false));
        }
        if (!Schema::hasColumn('pmp_files', 'production_factory_ids')) Schema::table('pmp_files', fn (Blueprint $t) => $t->json('production_factory_ids')->nullable());
        // Preserve company-specific names and the legacy IDs used by old files.
        DB::table('file_categories')->where('code', 'IQS')->whereIn('name', ['3D գծագրեր', '3D drawings', '3D чертежи'])->update(['name' => 'IGS']);
    }

    public function down(): void
    {
        // File plans and task metadata are retained to preserve production history.
    }
};
