<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Insert roles
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'guestUser']);
        Role::create(['name' => 'authenticatedUser']);
        Role::create(['name' => 'creator']);
    }
}
