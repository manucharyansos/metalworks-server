<?php

namespace App\Support;

use App\Models\WorkshopFunction;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Workshop functions ticked per file and copied onto each task job. */
final class WorkshopFunctions
{
    // Off until the migration has run, so reads never select missing columns.
    // Cached per request (the container), not per process.
    public static function enabled(): bool
    {
        $app = app();
        if (!$app->bound('workshop-functions.enabled')) {
            $app->instance('workshop-functions.enabled', Schema::hasTable('workshop_functions')
                && Schema::hasColumn('pmp_files', 'production_function_ids')
                && Schema::hasColumn('factory_order_files', 'production_functions'));
        }
        return (bool) $app->make('workshop-functions.enabled');
    }

    public static function rules(string $prefix = ''): array
    {
        return [$prefix.'production_function_ids' => 'sometimes|nullable|array|max:200',
            $prefix.'production_function_ids.*' => 'required|integer'];
    }

    /** Checked ids for a file going through $workshopIds; null when none were sent. */
    public static function validated(array $plan, array $workshopIds, string $field = 'production_function_ids'): ?array
    {
        if (!self::enabled() || !array_key_exists('production_function_ids', $plan) || $plan['production_function_ids'] === null) return null;
        $ids = array_values(array_unique(array_map('intval', $plan['production_function_ids'])));
        if (!$ids) return [];
        $valid = WorkshopFunction::where('is_active', true)->whereIn('factory_id', array_map('intval', $workshopIds))->whereIn('id', $ids)->count();
        if ($valid !== count($ids)) {
            throw ValidationException::withMessages([$field => ['Ընտրեք ֆայլի արտադրական ուղու արտադրամասերի գործող ֆունկցիաները։']]);
        }
        return $ids;
    }

    /** Functions chosen on each file, by file id and workshop id: [['id' => …, 'name' => …], …]. */
    public static function forFiles(iterable $files): array
    {
        if (!self::enabled()) return [];
        $files = collect($files);
        $ids = $files->flatMap(fn ($file) => array_map('intval', $file->production_function_ids ?? []))->unique()->values()->all();
        if (!$ids) return [];
        $catalog = WorkshopFunction::whereIn('id', $ids)->get(['id', 'factory_id', 'name'])->keyBy('id');
        $chosen = [];
        foreach ($files as $file) {
            foreach (array_map('intval', $file->production_function_ids ?? []) as $id) {
                $function = $catalog->get($id);
                if ($function) $chosen[(int) $file->id][(int) $function->factory_id][] = ['id' => $id, 'name' => $function->name];
            }
        }
        return $chosen;
    }

    /** Pivot attributes of one file in one workshop job. */
    public static function pivot(array $chosen, int $fileId, int $factoryId): array
    {
        return self::enabled() ? ['production_functions' => $chosen[$fileId][$factoryId] ?? null] : [];
    }
}
