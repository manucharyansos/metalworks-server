<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factory_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('factory_orders', 'paint_ral')) $table->string('paint_ral', 40)->nullable();
            if (!Schema::hasColumn('factory_orders', 'primer_required')) $table->boolean('primer_required')->default(false);
        });

        // Metalworks' file sections: which formats each workshop gets by default.
        // Other companies keep their own catalog; the manager can change it later.
        $company = DB::table('companies')->where('slug', 'metalworks')->value('id');
        if (!$company) return;

        DB::transaction(function () use ($company) {
            $section = fn (array $codes) => DB::table('file_categories')->where('company_id', $company)
                ->whereIn('code', $codes)->orderBy('id')->first();
            $formats = function ($section, array $add = [], array $remove = []) use ($company): void {
                if (!$section) return;
                $rows = DB::table('factory_file_extensions')->where('factory_id', $section->legacy_factory_id);
                if ($remove) (clone $rows)->whereIn('extension', $remove)->delete();
                foreach ($add as $extension) {
                    if ((clone $rows)->where('extension', $extension)->exists()) continue;
                    DB::table('factory_file_extensions')->insert(['company_id' => $company, 'factory_id' => $section->legacy_factory_id,
                        'extension' => $extension, 'created_at' => now(), 'updated_at' => now()]);
                }
            };

            // Lasers: DXF and DWG drawings; IGS tube models stay with the tube laser.
            $formats($section(['DXF']), ['dwg']);
            $formats($section(['IQS', 'IGS']), [], ['step', 'stp']);
            // Bending: its DLD programs.
            $formats($section(['DLD']), ['dld']);
            // SolidWorks and STEP models sit together. Every workshop of a task sees
            // them read-only, so they need no workshop of their own.
            $formats($section(['SW']), ['sldprt', 'sldasm', 'slddrw', 'step', 'stp']);
        });
    }

    public function down(): void
    {
        Schema::table('factory_orders', function (Blueprint $table) {
            foreach (['paint_ral', 'primer_required'] as $column) {
                if (Schema::hasColumn('factory_orders', $column)) $table->dropColumn($column);
            }
        });
        // File sections stay as they are: the manager may have edited them since.
    }
};
