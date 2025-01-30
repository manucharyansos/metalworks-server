<?php

namespace Database\Seeders;

use App\Models\FileExtension;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FileExtensionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        FileExtension::create(['extension' => 'pdf']);
        FileExtension::create(['extension' => 'dxf']);
    }
}
