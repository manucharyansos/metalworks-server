<?php

namespace Database\Seeders;

use App\Models\StoreLink;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class StoreLinkTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        StoreLink::create([
            'url' => 'https://www.metalvorks.com',
        ]);
    }
}
