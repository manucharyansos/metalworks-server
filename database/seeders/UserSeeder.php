<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Retrieve roles
        $adminRole = Role::where('name', 'admin')->first();
        $guestUserRole = Role::where('name', 'guestUser')->first();
        $authenticatedUserRole = Role::where('name', 'authenticatedUser')->first();
        $creatorRole = Role::where('name', 'creator')->first();

        // Create users with roles
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
    }
}
