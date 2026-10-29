<?php

namespace Database\Seeders;

use App\Models\{Factory, FileCategory};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class FileCategorySeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('file_categories')) return;
        $names = ['SW' => 'SW', 'DLD' => 'DLD', 'DXF' => 'DXF', 'IQS' => 'IGS', 'INFO' => 'INFO', 'PDF' => 'PDF'];
        foreach (Factory::whereIn('value', array_keys($names))->get() as $factory) {
            FileCategory::firstOrCreate(['legacy_factory_id' => $factory->id], ['name' => $names[$factory->value], 'code' => $factory->value,
                'default_factory_id' => $factory->is_workshop && !$factory->is_reference ? $factory->id : null,
                'is_reference' => $factory->is_reference, 'is_active' => true]);
        }
    }
}
