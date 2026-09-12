<?php

namespace Database\Seeders;

use App\Models\BendFileExtension;
use Illuminate\Database\Seeder;

class BendFileExtensionSeeder extends Seeder
{
    public function run(): void
    {
        BendFileExtension::firstOrCreate(['extension' => 'pdf']);
    }
}
