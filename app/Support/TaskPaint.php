<?php

namespace App\Support;

use App\Models\{Factory, FactoryOrder, OrderLog, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The colour (RAL) and the primer request of a powder-coating job. */
final class TaskPaint
{
    public static function rules(string $prefix = ''): array
    {
        return [$prefix.'paint_ral' => 'nullable|string|max:40', $prefix.'primer_required' => 'sometimes|boolean'];
    }

    public static function isPowder(?Factory $factory): bool
    {
        if (!$factory) return false;
        if (in_array(strtoupper(trim((string) $factory->value)), ['POWDER', 'POWDER_CATTING', 'PAINT'], true)) return true;
        return (bool) preg_match('/powder|paint|փոշե|ներկ|порош|покрас/iu', (string) $factory->name);
    }

    /** "9005", "ral9005" and "RAL 9005" are all stored as "RAL 9005". */
    public static function ral(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') return null;
        if (preg_match('/^\d{4}$/', $value)) return 'RAL '.$value;
        return preg_replace('/^ral\s*/iu', 'RAL ', $value);
    }

    /** Settings for a new job: only a powder-coating job keeps them. */
    public static function fields(array $data, ?Factory $factory): array
    {
        if (!self::isPowder($factory)) return [];
        return ['paint_ral' => self::ral($data['paint_ral'] ?? null), 'primer_required' => (bool) ($data['primer_required'] ?? false)];
    }

    public static function update(User $actor, FactoryOrder $step, array $data): FactoryOrder
    {
        return DB::transaction(function () use ($actor, $step, $data) {
            $locked = FactoryOrder::whereKey($step->id)->lockForUpdate()->firstOrFail();
            if (!self::isPowder($locked->factory)) {
                throw ValidationException::withMessages(['paint_ral' => ['Գույնը նշվում է միայն փոշեներկման աշխատանքի համար։']]);
            }
            if (!TaskFileVersions::editable($locked)) {
                throw ValidationException::withMessages(['paint_ral' => ['Ավարտված կամ չեղարկված աշխատանքի գույնը փոխել հնարավոր չէ։']]);
            }
            $next = ['paint_ral' => self::ral($data['paint_ral'] ?? null),
                'primer_required' => array_key_exists('primer_required', $data) ? (bool) $data['primer_required'] : (bool) $locked->primer_required];
            $before = ['paint_ral' => $locked->paint_ral, 'primer_required' => (bool) $locked->primer_required];
            if ($next === $before) return $locked;
            $locked->update($next);
            OrderLog::create(['order_id' => $locked->order_id, 'user_id' => $actor->id, 'action' => 'factory_order.paint_changed',
                'message' => sprintf('Արտադրամաս «%s»․ գույն՝ %s → %s, գրունտ՝ %s → %s', $locked->factory->name,
                    $before['paint_ral'] ?? '—', $next['paint_ral'] ?? '—', $before['primer_required'] ? 'այո' : 'ոչ', $next['primer_required'] ? 'այո' : 'ոչ'),
                'meta' => ['factory_order_id' => $locked->id, 'factory_id' => $locked->factory_id, 'from' => $before, 'to' => $next]]);
            return $locked;
        });
    }
}
