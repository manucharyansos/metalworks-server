<?php

namespace Database\Seeders;

use App\Models\FactoryOrderStatus;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleTableSeeder::class,
            FactorySeeder::class,
            UserSeeder::class,
            FileExtensionSeeder::class,
            LaserFileExtensionSeeder::class,
            BendFileExtensionSeeder::class,
            FactoryOrderStatusSeeder::class,
            MaterialsSeeder::class,
             ClientSeeder::class,
        ]);
    }
}
