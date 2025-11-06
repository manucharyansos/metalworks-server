<?php

namespace Database\Seeders;

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
            UserSeeder::class,
            FactorySeeder::class,
            FileExtensionSeeder::class,
            LaserFileExtensionSeeder::class,
            BendFileExtensionSeeder::class,
            // PmpSeeder::class,
            MaterialsSeeder::class,
             ClientSeeder::class,
        ]);
    }
}
