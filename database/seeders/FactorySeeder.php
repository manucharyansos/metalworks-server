<?php

namespace Database\Seeders;

use App\Models\Factories;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FactorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Factories::create(['name' => 'SolidWorks']);
        Factories::create(['name' => 'Bend']);
        Factories::create(['name' => 'Laser cutting']);
        Factories::create(['name' => 'Powder Catting']);
    }
}
