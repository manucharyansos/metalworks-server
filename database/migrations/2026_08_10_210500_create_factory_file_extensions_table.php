<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factory_file_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->cascadeOnDelete();
            $table->string('extension', 20);
            $table->timestamps();

            $table->unique(['factory_id', 'extension']);
            $table->index('extension');
        });

        $now = now();

        $insert = function (?int $factoryId, iterable $extensions) use ($now): void {
            if (!$factoryId) {
                return;
            }

            foreach ($extensions as $extension) {
                $normalized = strtolower(ltrim(trim((string) $extension), '.'));

                if ($normalized === '') {
                    continue;
                }

                DB::table('factory_file_extensions')->insertOrIgnore([
                    'factory_id' => $factoryId,
                    'extension' => $normalized,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        };

        $factoryIds = DB::table('factories')
            ->whereIn('value', ['SW', 'DLD', 'DXF', 'IQS', 'INFO', 'PDF'])
            ->pluck('id', 'value');

        // Preserve the exact legacy upload rules while making every factory configurable.
        $insert($factoryIds['SW'] ?? null, ['sldprt', 'sldasm', 'slddrw']);
        $insert($factoryIds['IQS'] ?? null, ['iqs']);
        $insert($factoryIds['INFO'] ?? null, ['txt', 'csv']);
        $insert($factoryIds['PDF'] ?? null, ['pdf']);

        if (Schema::hasTable('bend_file_extensions')) {
            $insert(
                $factoryIds['DLD'] ?? null,
                DB::table('bend_file_extensions')->pluck('extension')
            );
        }

        if (Schema::hasTable('laser_file_extensions')) {
            $insert(
                $factoryIds['DXF'] ?? null,
                DB::table('laser_file_extensions')->pluck('extension')
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('factory_file_extensions');
    }
};
