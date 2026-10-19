<?php

namespace Database\Seeders;

use App\Models\Factory;
use Illuminate\Database\Seeder;

class FactorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $factories = [
            'SW' => 'Engineering',
            'DLD' => 'Bend',
            'DXF' => 'Laser cutting',
            'IQS' => 'Laser',
            'INFO' => 'Informal',
            'PDF' => 'PDF',
            'POWDER' => 'Փոշեներկում',
        ];

        foreach ($factories as $value => $name) {
            Factory::firstOrCreate(
                ['value' => $value],
                ['name' => $name] + (\Illuminate\Support\Facades\Schema::hasColumn('factories', 'is_workshop') ? ['is_workshop' => !in_array($value, ['SW', 'INFO', 'PDF'], true), 'is_active' => true] : [])
            );
        }
        (new FileCategorySeeder)->run();
    }
}
