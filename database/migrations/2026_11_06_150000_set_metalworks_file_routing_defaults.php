<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $company = DB::table('companies')->where('slug', 'metalworks')->value('id');
        if (!$company) return;

        DB::transaction(function () use ($company) {
            $sections = DB::table('file_categories')->where('company_id', $company);
            $workshops = DB::table('factories')->where('company_id', $company)
                ->where('is_active', true)->where('is_workshop', true)->where('is_reference', false);
            foreach (['DLD' => ['DLD', 'BEND'], 'DXF' => ['DXF', 'LASER_SHEETS'],
                'IQS' => ['IQS', 'LASER_TUBES'], 'IGS' => ['IQS', 'LASER_TUBES']] as $code => $values) {
                $id = (clone $workshops)->where('value', $values[0])->value('id')
                    ?? (clone $workshops)->where('value', $values[1])->value('id');
                if ($id) (clone $sections)->where('code', $code)->update(['default_factory_id' => $id, 'is_reference' => false]);
            }
            (clone $sections)->where('code', 'SW')->update(['default_factory_id' => null]);
        });
    }

    public function down(): void
    {
        // Catalog preferences may subsequently be edited by the manager.
    }
};
