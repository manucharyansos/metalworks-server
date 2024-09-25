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
        Factory::create(['name' => 'SolidWorks']);
        Factory::create(['name' => 'Bend']);
        Factory::create(['name' => 'Laser cutting']);
        Factory::create(['name' => 'Powder Catting']);
    }
}
