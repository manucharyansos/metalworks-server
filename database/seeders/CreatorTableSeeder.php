<?php

namespace Database\Seeders;

use App\Models\Creator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CreatorTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Creator::create([
            'name' => 'Creator 1',
        ]);
    }
}
