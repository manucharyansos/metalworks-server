<?php

namespace Database\Seeders;

use App\Models\Factory;
use App\Models\Role;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->first();
        $managerRole = Role::where('name', 'manager')->first();
        $laserRole = Role::where('name', 'laser')->first();
        $bendRole = Role::where('name', 'bend')->first();
        $cattingRole = Role::where('name', 'powder_catting')->first();
        $engineerRole = Role::where('name', 'engineer')->first();

        $factoryBend = Factory::where('name', 'Bend')->first();
        $factoryLaserCutting = Factory::where('name', 'Laser cutting')->first();
        $factoryLaser = Factory::where('name', 'Laser')->first();
        $factoryPowder = Factory::where('name', 'Informal')->first();

        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role_id' => $adminRole->id,
        ]);

        User::create([
            'name' => 'Manager 1',
            'email' => 'manager@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $managerRole->id,
        ]);

        User::create([
            'name' => 'Manager 2',
            'email' => 'manager2@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $managerRole->id,
        ]);

        User::create([
            'name' => 'Engineer',
            'email' => 'engineering@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $engineerRole->id,
        ]);

        User::create([
            'name' => 'Engineer 2',
            'email' => 'engineer2@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $engineerRole->id,
        ]);

        User::create([
            'name' => 'Bend 1',
            'email' => 'bend@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $bendRole->id,
            'factory_id' => optional($factoryBend)->id,
        ]);

        User::create([
            'name' => 'Bend 2',
            'email' => 'bend2@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $bendRole->id,
            'factory_id' => optional($factoryBend)->id,
        ]);

        User::create([
            'name' => 'Catting 1',
            'email' => 'catting@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $cattingRole->id,
            'factory_id' => optional($factoryPowder)->id,
        ]);

        User::create([
            'name' => 'Catting 2',
            'email' => 'catting2@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $cattingRole->id,
            'factory_id' => optional($factoryPowder)->id,
        ]);

        User::create([
            'name' => 'Laser 1',
            'email' => 'laser@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $laserRole->id,
            'factory_id' => optional($factoryLaserCutting)->id,
        ]);

        User::create([
            'name' => 'Laser 2',
            'email' => 'laser2@metalworks.am',
            'password' => Hash::make('password'),
            'role_id' => $laserRole->id,
            'factory_id' => optional($factoryLaserCutting)->id,
        ]);
    }
}
