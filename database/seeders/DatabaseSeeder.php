<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Materials;
use App\Models\Order;
use App\Models\Pmp;
use App\Models\PrefixCode;
use App\Models\Status;
use App\Models\StoreLink;
use App\Models\User;
use App\Models\Products;
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
        $this->call(FileExtensionSeeder::class);
        $this->call(LaserFileExtensionSeeder::class);
        $this->call(BendFileExtensionSeeder::class);
//         $this->call(PmpSeeder::class);
        $this->call(MaterialsSeeder::class);
    }
}
