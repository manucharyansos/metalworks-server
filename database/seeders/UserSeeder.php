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
        $creatorRole = Role::where('name', 'creator')->first();
        $laserRole = Role::where('name', 'laser')->first();
        $bendRole = Role::where('name', 'bend')->first();
        $cattingRole = Role::where('name', 'powder_catting')->first();

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
            'name' => 'Creator User',
            'email' => 'creator@example.com',
            'password' => Hash::make('password'),
            'role_id' => $creatorRole->id,
        ]);

        User::create([
            'name' => 'Laser User',
            'email' => 'laser@example.com',
            'password' => Hash::make('password'),
            'role_id' => $laserRole->id,
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
    }
}
