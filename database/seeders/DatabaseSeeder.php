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
            RoleTableSeeder::class,      // Roles
            PermissionSeeder::class,     // Base permissions
            RolePermissionSeeder::class, // Role ↔ Permission կապեր
            UserSeeder::class,           // Users with roles
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
