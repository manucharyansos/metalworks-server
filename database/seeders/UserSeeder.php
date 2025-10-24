<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->first();
        $guestUserRole = Role::where('name', 'guestUser')->first();
        $authenticatedUserRole = Role::where('name', 'authenticatedUser')->first();
        $managerRole = Role::where('name', 'manager')->first();
        $laserRole = Role::where('name', 'laser')->first();
        $bendRole = Role::where('name', 'bend')->first();
        $cattingRole = Role::where('name', 'powder_catting')->first();
        $engineerRole = Role::where('name', 'engineer')->first();

        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role_id' => $adminRole->id,
        ]);

        User::create([
            'name' => 'Guest User',
            'email' => 'guest@example.com',
            'password' => Hash::make('password'),
            'role_id' => $guestUserRole->id,
        ]);

        User::create([
            'name' => 'Authenticated User',
            'email' => 'authenticated@example.com',
            'password' => Hash::make('password'),
            'role_id' => $authenticatedUserRole->id,
        ]);

        User::create([
            'name' => 'Mnager User',
            'email' => 'manager@example.com',
            'password' => Hash::make('password'),
            'role_id' => $managerRole->id,
        ]);

        User::create([
            'name' => 'Bend User',
            'email' => 'bend@example.com',
            'password' => Hash::make('password'),
            'role_id' => $bendRole->id,
        ]);

        User::create([
            'name' => 'Catting User',
            'email' => 'catting@example.com',
            'password' => Hash::make('password'),
            'role_id' => $cattingRole->id,
        ]);

        User::create([
            'name' => 'Laser 1',
            'email' => 'laser1@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $laserRole->id,
        ]);

        User::create([
            'name' => 'Laser 2',
            'email' => 'laser2@metalworks.am',
            'password' => Hash::make('u;KrS)I$.Oxl'),
            'role_id' => $laserRole->id,
        ]);

        User::create([
            'name' => 'Engineer',
            'email' => 'engineering@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $engineerRole->id,
        ]);
    }
}
