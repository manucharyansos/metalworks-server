<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'guestUser']);
        Role::create(['name' => 'authenticatedUser']);
        Role::create(['name' => 'creator']);
        Role::create(['name' => 'bend']);
        Role::create(['name' => 'laser']);
        Role::create(['name' => 'powder_catting']);
    }
}
