<?php

namespace App\Support;

class PermissionMap
{
    public static function all(): array
    {
        return config('permissions', []);
    }

    public static function allSlugs(): array
    {
        $all = self::all();
        $slugs = [];

        foreach ($all as $module => $actions) {
            foreach ($actions as $action => $label) {
                $slugs[] = "{$module}.{$action}";
            }
        }

        return $slugs;
    }

    public static function label(string $slug): ?string
    {
        [$module, $action] = array_pad(explode('.', $slug, 2), 2, null);

        if (!$module || !$action) {
            return null;
        }

        return self::all()[$module][$action] ?? null;
    }
}
