<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleTableSeeder extends Seeder
{
    public function run(): void
    {
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'guestUser']);
        Role::create(['name' => 'authenticatedUser']);
        Role::create(['name' => 'manager']);
        Role::create(['name' => 'bend']);
        Role::create(['name' => 'laser']);
        Role::create(['name' => 'powder_catting']);
    }
}
