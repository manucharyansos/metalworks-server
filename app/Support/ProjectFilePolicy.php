<?php

namespace App\Support;

use App\Models\{BendFileExtension, Factory, FactoryFileExtension};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ProjectFilePolicy
{
    /** Workshop staff may look at these models in the app but never download them. */
    public const VIEW_ONLY_EXTENSIONS = ['sldprt', 'sldasm', 'slddrw', 'sw', 'step', 'stp'];

    public static function isSolidWorksName(?string $name): bool
    {
        return in_array(strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)), ['sldprt', 'sldasm', 'slddrw', 'sw'], true);
    }

    public static function isViewOnlyName(?string $name): bool
    {
        return in_array(strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)), self::VIEW_ONLY_EXTENSIONS, true);
    }

    /**
     * The app's file viewer sends this header with its request. A link opened
     * in a tab or a download manager cannot, so a view-only file never leaves
     * the viewer as a download.
     */
    public static function isPreviewRequest(Request $request): bool
    {
        return $request->header('X-File-Preview') === '1';
    }

    /** Whether this request may receive the named file: workshop staff get view-only files only in the viewer. */
    public static function operatorMayReceive(Request $request, ?string ...$names): bool
    {
        $user = $request->user();
        if (!$user || !TaskAccess::isOperator($user)) return true;
        foreach ($names as $name) {
            if (self::isViewOnlyName($name)) return !$request->boolean('download') && self::isPreviewRequest($request);
        }
        return true;
    }

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
