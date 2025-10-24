<?php

namespace Database\Seeders;

use App\Models\Manager;
use App\Models\Description;
use App\Models\Order;
use App\Models\PrefixCode;
use App\Models\Status;
use App\Models\StoreLink;
use Illuminate\Database\Seeder;

class OrdersTableSeeder extends Seeder
{
    public function run(): void
    {
        // Seed the related tables first
        $descriptions = Description::factory(10)->create();
        $statuses = Status::factory(5)->create();
        $prefixCodes = PrefixCode::factory(5)->create();
        $creators = Manager::factory(5)->create();
        $storeLinks = StoreLink::factory(5)->create();

        // Now create orders
        Order::factory(20)->create([
            'description_id' => $descriptions->random()->id,
            'status_id' => $statuses->random()->id,
            'prefix_code_id' => $prefixCodes->random()->id,
            'creator_id' => $creators->random()->id,
            'store_link_id' => $storeLinks->random()->id,
        ]);
    }
}
