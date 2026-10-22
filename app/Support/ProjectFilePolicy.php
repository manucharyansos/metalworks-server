<?php

namespace App\Support;

use App\Models\{BendFileExtension, Factory, FactoryFileExtension};
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ProjectFilePolicy
{
    public static function needsAttributes(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['dxf', 'ic'], true);
    }

    public static function extensions(Factory $factory): array
    {
        // The database is authoritative, including an intentionally empty list.
        // Defaults only support a rolling deployment before the policy migration.
        $extensions = Schema::hasTable('factory_file_extensions')
            ? FactoryFileExtension::where('factory_id', $factory->id)->orderBy('extension')->pluck('extension')->all()
            : match ($factory->value) {
                'SW' => ['sldprt', 'sldasm', 'slddrw'],
                'DLD' => BendFileExtension::pluck('extension')->all(),
                'DXF' => ['dxf'],
                'IQS' => ['iqs', 'igs', 'iges', 'step', 'stp', 'stl', 'obj'],
                'INFO' => ['*'],
                'PDF' => ['pdf'],
                default => [],
            };

        return array_values(array_unique(array_filter(array_map(
            static fn ($extension) => strtolower(ltrim(trim((string) $extension), '.')),
            $extensions
        ))));
    }

    public static function assertAllowed(Factory $factory, string $filename): void
    {
        $allowed = self::extensions($factory);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array('*', $allowed, true) && !in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages(['file' => [$allowed
                ? 'Ֆայլի տեսակը թույլատրված չէ։ Թույլատրելի են՝ '.implode(', ', $allowed)
                : 'Այս ֆայլերի բաժնի համար թույլատրելի ձևաչափ դեռ սահմանված չէ։']]);
        }
    }
}
