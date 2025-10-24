<?php

namespace Database\Seeders;

use App\Models\Factory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FactorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Factory::create(['name' => 'SolidWorks', 'value' => 'SW']);
        Factory::create(['name' => 'Bend', 'value' => 'DLD']);
        Factory::create(['name' => 'Laser cutting', 'value' => 'DXF']);
        Factory::create(['name' => 'Laser', 'value' => 'IQS']);
        Factory::create(['name' => 'Informal', 'value' => 'INFO']);
        Factory::create(['name' => 'PDF', 'value' => 'PDF']);
    }
}
