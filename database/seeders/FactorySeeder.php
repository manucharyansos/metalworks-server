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
        ];

        foreach ($factories as $value => $name) {
            Factory::updateOrCreate(
                ['value' => $value],
                ['name' => $name]
            );
        }
    }
}
