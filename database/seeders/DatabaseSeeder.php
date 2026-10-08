<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed only the system data required by a production installation.
     */
    public function run(): void
    {
        $this->call(RoleTableSeeder::class);
        $seed = fn () => $this->call([
            FactorySeeder::class, UserSeeder::class,
            FactoryFileExtensionSeeder::class, FactoryOrderStatusSeeder::class,
        ]);
        if (\Illuminate\Support\Facades\Schema::hasTable('companies')) {
            app(\App\Support\CompanyContext::class)->run(\App\Models\Company::where('slug', 'metalworks')->firstOrFail(), $seed);
        } else {
            $seed();
        }
    }
}
