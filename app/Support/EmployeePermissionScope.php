<?php

namespace App\Support;

use App\Models\User;

class EmployeePermissionScope
{
    /**
     * Return only the user-level permission slugs that an admin is allowed
     * to override for this employee. Role permissions themselves are never
     * modified here.
     *
     * @return array<int, string>
     */
    public static function slugsFor(User $user): array
    {
        $roleName = $user->role?->name;
        $allSlugs = PermissionMap::allSlugs();

        if ($roleName === 'admin') {
            return [];
        }

        if ($roleName === 'engineer') {
            return array_values(array_filter(
                $allSlugs,
                fn (string $slug): bool => self::isEngineerOverride($slug)
            ));
        }

        if ($roleName === 'manager') {
            return array_values(array_filter(
                $allSlugs,
                fn (string $slug): bool => !self::isFilePermission($slug)
            ));
        }

        if (
            $user->factory_id !== null
            || in_array($roleName, ['bend', 'laser', 'powder_catting'], true)
        ) {
            return array_values(array_filter(
                $allSlugs,
                fn (string $slug): bool => $slug === 'factory.download'
            ));
        }

        return [];
    }

    private static function isEngineerOverride(string $slug): bool
    {
        return str_starts_with($slug, 'pmp.')
            || str_starts_with($slug, 'pmp_group.')
            || str_starts_with($slug, 'pmp_files.')
            || in_array($slug, ['clients.view', 'workers.view'], true);
    }

    private static function isFilePermission(string $slug): bool
    {
        return str_starts_with($slug, 'pmp.')
            || str_starts_with($slug, 'pmp_group.')
            || str_starts_with($slug, 'pmp_files.')
            || $slug === 'factory.download';
    }
}
