<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class FileWorkshopInstructions
{
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'production_instructions' => 'sometimes|nullable|array|max:100',
            $prefix.'production_instructions.*' => 'array:factory_id,text',
            $prefix.'production_instructions.*.factory_id' => 'required|integer',
            $prefix.'production_instructions.*.text' => 'nullable|string|max:1000',
        ];
    }

    public static function validated(array $plan, array $path, string $field = 'production_instructions'): array
    {
        $rows = []; $seen = [];
        foreach ($plan['production_instructions'] ?? [] as $row) {
            $id = (int) $row['factory_id'];
            if (isset($seen[$id]) || !in_array($id, $path, true)) {
                throw ValidationException::withMessages([$field => ['Հանձնարարությունը պետք է պատկանի ֆայլի ընտրված փուլին՝ մեկ անգամ։']]);
            }
            $seen[$id] = true;
            $text = trim($row['text'] ?? '');
            if ($text !== '') $rows[] = ['factory_id' => $id, 'text' => $text];
        }
        if ($rows && (!Schema::hasColumn('pmp_files', 'production_instructions') || !WorkshopFunctions::enabled())) {
            throw ValidationException::withMessages([$field => ['Հանձնարարությունների պահպանումը դեռ հասանելի չէ։ Թարմացրեք համակարգի տվյալների բազան։']]);
        }
        return $rows;
    }
}
