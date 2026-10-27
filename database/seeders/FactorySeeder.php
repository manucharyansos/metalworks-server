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
            'DLD' => 'Կռում',
            'DXF' => 'Լազերային կտրում՝ լիստեր',
            'IQS' => 'Լազերային կտրում՝ խողովակներ',
            'INFO' => 'Informal',
            'PDF' => 'PDF',
            'POWDER' => 'Փոշեներկում',
            'WELD' => 'Զոդում',
            'ROLL' => 'Վալց',
        ];

        foreach ($factories as $value => $name) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('factories', 'is_workshop')
                && !in_array($value, ['SW', 'INFO', 'PDF'], true)
                && Factory::where('name', $name)->where('is_workshop', true)->where('is_reference', false)->exists()) continue;
            Factory::firstOrCreate(
                ['value' => $value],
                ['name' => $name] + (\Illuminate\Support\Facades\Schema::hasColumn('factories', 'is_workshop') ? ['is_workshop' => !in_array($value, ['SW', 'INFO', 'PDF'], true), 'is_active' => true] : [])
            );
        }
        (new FileCategorySeeder)->run();
    }
}
