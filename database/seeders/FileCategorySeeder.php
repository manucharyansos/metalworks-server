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
        $names = ['SW' => 'CAD նախագծեր', 'DLD' => 'Կռման գծագրեր', 'DXF' => 'DXF գծագրեր', 'IQS' => '3D գծագրեր', 'INFO' => 'Տեղեկատու ֆայլեր', 'PDF' => 'PDF փաստաթղթեր'];
        foreach (Factory::whereIn('value', array_keys($names))->get() as $factory) {
            FileCategory::firstOrCreate(['legacy_factory_id' => $factory->id], ['name' => $names[$factory->value], 'code' => $factory->value,
                'default_factory_id' => $factory->is_workshop && !$factory->is_reference ? $factory->id : null,
                'is_reference' => $factory->is_reference, 'is_active' => true]);
        }
    }
}
