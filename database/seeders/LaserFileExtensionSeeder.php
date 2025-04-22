<?php

namespace Database\Seeders;

use App\Models\LaserFileExtension;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LaserFileExtensionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // LaserFileExtension::create(['extension' => 'pdf']);
        LaserFileExtension::create(['extension' => 'dxf']);
    }
}
