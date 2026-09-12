<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed only the system data required by a production installation.
     */
    public function run(): void
    {
        $this->call([
            RoleTableSeeder::class,
            FactorySeeder::class,
            UserSeeder::class,
            FactoryFileExtensionSeeder::class,
            FactoryOrderStatusSeeder::class,
        ]);
    }
}
