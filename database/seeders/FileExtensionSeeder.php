<?php

namespace Database\Seeders;

use App\Models\FileExtension;
use Illuminate\Database\Seeder;

class FileExtensionSeeder extends Seeder
{
    public function run(): void
    {
        // Legacy table compatibility. Current per-factory rules are maintained by
        // FactoryFileExtensionSeeder and this seeder can be run repeatedly safely.
        foreach (['pdf', 'dxf'] as $extension) {
            FileExtension::firstOrCreate(['extension' => $extension]);
        }

        $this->call(FactoryFileExtensionSeeder::class);
    }
}
