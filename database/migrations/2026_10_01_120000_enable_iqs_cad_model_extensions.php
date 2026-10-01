<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODEL_EXTENSIONS = ['iges', 'igs', 'obj', 'step', 'stl', 'stp'];

    public function up(): void
    {
        if (! Schema::hasTable('factories') || ! Schema::hasTable('factory_file_extensions')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (DB::table('factories')->where('value', 'IQS')->pluck('id') as $factoryId) {
                $extensions = DB::table('factory_file_extensions')
                    ->where('factory_id', $factoryId)->orderBy('extension')->pluck('extension')->all();

                // Only extend the legacy default. Keep custom and empty policies intact.
                if ($extensions !== ['iqs']) {
                    continue;
                }

                foreach (self::MODEL_EXTENSIONS as $extension) {
                    DB::table('factory_file_extensions')->insertOrIgnore([
                        'factory_id' => $factoryId,
                        'extension' => $extension,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('factories') || ! Schema::hasTable('factory_file_extensions')) {
            return;
        }

        $defaults = array_merge(self::MODEL_EXTENSIONS, ['iqs']);
        sort($defaults);

        DB::transaction(function () use ($defaults): void {
            foreach (DB::table('factories')->where('value', 'IQS')->pluck('id') as $factoryId) {
                $extensions = DB::table('factory_file_extensions')
                    ->where('factory_id', $factoryId)->orderBy('extension')->pluck('extension')->all();

                // A subsequent admin edit takes priority over a default-policy rollback.
                if ($extensions === $defaults) {
                    DB::table('factory_file_extensions')->where('factory_id', $factoryId)
                        ->whereIn('extension', self::MODEL_EXTENSIONS)->delete();
                }
            }
        });
    }
};
