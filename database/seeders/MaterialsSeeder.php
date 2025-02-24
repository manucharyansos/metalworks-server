<?php

namespace Database\Seeders;

use App\Models\Material;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\File;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MaterialsSeeder extends Seeder
{
    public function run()
    {
        Material::factory(10)->create();
    }
}
