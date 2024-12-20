<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => $this->faker->unique()->numerify('ORD###'),
            'description_id' => \App\Models\Description::factory(),
            'prefix_code_id' => \App\Models\PrefixCode::factory(),
            'manager_id' => \App\Models\Manager::factory(),
            'store_link_id' => \App\Models\StoreLink::factory(),
            'status_id' => \App\Models\Status::factory(),
        ];
    }
}
