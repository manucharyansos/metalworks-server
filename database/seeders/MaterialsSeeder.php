<?php

namespace Database\Seeders;

use App\Models\Materials;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\File;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MaterialsSeeder extends Seeder
{
    public function run()
    {
        Materials::factory(10)->create();
    }
}
