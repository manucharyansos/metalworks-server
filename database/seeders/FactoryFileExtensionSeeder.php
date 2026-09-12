<?php

namespace Database\Seeders;

use App\Models\Factory;
use App\Models\FactoryFileExtension;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FactoryFileExtensionSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('factory_file_extensions')) {
            $this->command?->warn('factory_file_extensions table is missing; run migrations first.');
            return;
        }

        // Keep these rules deterministic. INFO intentionally uses "*" to mean
        // every non-dangerous upload type; RejectDangerousUploads still blocks
        // executable/server-side formats globally.
        $rules = [
            'INFO' => ['*'],
            'SW' => ['sldprt', 'sldasm', 'slddrw'],
            'IQS' => ['iqs'],
            'PDF' => ['pdf'],
            'DXF' => ['dxf'],
        ];

        DB::transaction(function () use ($rules): void {
            foreach ($rules as $factoryValue => $extensions) {
                $factory = Factory::query()->firstWhere('value', $factoryValue);

                if (! $factory) {
                    $this->command?->warn("Factory '{$factoryValue}' was not found; extension rules were skipped.");
                    continue;
                }

                FactoryFileExtension::query()
                    ->where('factory_id', $factory->id)
                    ->whereNotIn('extension', $extensions)
                    ->delete();

                foreach ($extensions as $extension) {
                    FactoryFileExtension::updateOrCreate([
                        'factory_id' => $factory->id,
                        'extension' => $extension,
                    ]);
                }
            }
        });
    }
}
