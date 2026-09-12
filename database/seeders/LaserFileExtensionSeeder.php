<?php

namespace Database\Seeders;

use App\Models\LaserFileExtension;
use Illuminate\Database\Seeder;

class LaserFileExtensionSeeder extends Seeder
{
    public function run(): void
    {
        LaserFileExtension::firstOrCreate(['extension' => 'dxf']);
    }
}
