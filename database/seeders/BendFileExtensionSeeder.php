<?php

namespace Database\Seeders;

use App\Models\BendFileExtension;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BendFileExtensionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        BendFileExtension::create(['extension' => 'pdf']);
        // BendFileExtension::create(['extension' => 'dxf']);
    }
}
