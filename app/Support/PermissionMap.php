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
}
