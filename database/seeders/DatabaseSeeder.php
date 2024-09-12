<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Materials;
use App\Models\Order;
use App\Models\PrefixCode;
use App\Models\Status;
use App\Models\StoreLink;
use App\Models\User;
use Database\Factories\CreatorFactory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleTableSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(FactorySeeder::class);
//         User::factory(10)->create();
//         Creator::factory(10)->create();
//         Description::factory(10)->create();
//         Order::factory(10)->create();
//         PrefixCode::factory(10)->create();
//         Status::factory(10)->create();
//         StoreLink::factory(10)->create();

//         \App\Models\User::factory()->create([
//             'name' => 'Test User',
//             'email' => 'test@example.com',
//         ]);

    }
}
