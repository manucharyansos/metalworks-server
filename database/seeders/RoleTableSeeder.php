<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleTableSeeder extends Seeder
{
    public function run(): void
    {
        Role::create(['name' => 'admin', 'value' => 'Ադմին']);
        Role::create(['name' => 'guestUser', 'value' => 'Չգրանցված օգտատեր']);
        Role::create(['name' => 'authenticatedUser', 'value' => 'Գրանցված օգտատեր']);
        Role::create(['name' => 'manager' , 'value' => 'Մենեջեր']);
        Role::create(['name' => 'bend', 'value' => 'Կռում']);
        Role::create(['name' => 'laser', 'value' => 'Լազերաին կտրում']);
        Role::create(['name' => 'powder_catting', 'value' => 'Փոշեներկում']);
        Role::create(['name' => 'engineer', 'value' => 'Ինժիներ']);
    }
}
