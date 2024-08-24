<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;

class ServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Service::create([
            'name' => 'Materials',
            'description' => 'Full-stack web development services.'
        ]);

        Service::create([
            'name' => 'Services',
            'description' => 'Search engine optimization to increase visibility.'
        ]);

        Service::create([
            'name' => 'Resources',
            'description' => 'Creative design solutions for your brand.'
        ]);

        Service::create([
            'name' => 'Examples',
            'description' => 'Creative design solutions for your brand.'
        ]);

        Service::create([
            'name' => 'Contact',
            'description' => 'Creative design solutions for your brand.'
        ]);

        // You can add more sample data here as needed
    }
}
