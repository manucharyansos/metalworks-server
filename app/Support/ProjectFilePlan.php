<?php

namespace App\Support;

use App\Models\{Factory, Pmp};
use Illuminate\Validation\ValidationException;

final class ProjectFilePlan
{
    public static function rules(string $prefix = ''): array
    {
        return [$prefix.'production_factory_ids' => 'sometimes|nullable|array|max:100',
            $prefix.'production_factory_ids.*' => 'required|integer',
            $prefix.'inspection_required' => 'sometimes|boolean'];
    }

    public static function validate(array $plan, bool $reference, string $field = 'production_factory_ids'): void
    {
        $ids = array_map('intval', $plan['production_factory_ids'] ?? []);
        if (($reference && $ids) || count(array_unique($ids)) !== count($ids)
            || Factory::activeWorkshops()->whereIn('id', $ids)->sharedLock()->get(['id'])->count() !== count($ids)) {
            throw ValidationException::withMessages([$field => ['Ընտրեք այս ընկերության գործող արտադրամասերը։ Տեղեկատու ֆայլերի տեսանելիությունը նշվում է առաջադրանքում։']]);
        }
    }

    public static function taskPlan(Pmp $pmp, array $selected, array $routing, array $visibility): ?array
    {
        $ids = TaskWorkflow::workshopIds($pmp, $selected, $routing, $visibility);
        $graph = array_fill_keys($ids, []); $ordered = []; $hasPlan = false;
        foreach ($pmp->files->whereIn('id', array_column($selected, 'id'))->reject(fn ($f) => $f->is_reference_file) as $file) {
            if ($file->production_factory_ids === null) continue;
            $hasPlan = true;
            $targets = TaskWorkflow::destinations($file, $routing);
            $path = array_values(array_intersect($file->production_factory_ids, $targets));
            $path = array_merge($path, array_values(array_diff($targets, $path)));
            $previous = null;
            foreach ($path as $id) {
                if (!in_array($id, $ordered, true)) $ordered[] = $id;
                if ($previous !== null) $graph[$id][] = $previous;
                $previous = $id;
            }
        }
        if (!$hasPlan) return null;
        $ordered = array_merge($ordered, array_values(array_diff($ids, $ordered)));
        foreach ($graph as &$dependencies) { $dependencies = array_values(array_unique($dependencies)); sort($dependencies); }
        unset($dependencies);
        ProductionRoute::validateGraph($graph);
        return ['mode' => 'custom', 'stages' => array_map(fn ($id) => ['factory_id' => (int) $id,
            'predecessor_factory_ids' => $graph[$id], 'deadline' => null], $ordered)];
    }
}
