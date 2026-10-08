<?php

namespace App\Support;

final class PermissionScope
{
    private const FULL_ACCESS_ROLES = ['admin', 'manager'];

    private const ENGINEER_PERMISSIONS = [
        'orders.view',
        'orders.create',
        'orders.update',
        'orders.delete',
        'pmp.view',
        'pmp.create',
        'pmp.update',
        'pmp_files.view',
        'pmp_files.upload',
        'pmp_files.delete',
        'pmp_group.check_group',
        'pmp_group.check_group_name',
        'pmp_group.check_remote_number',
        'clients.view',
        'factory.view',
    ];

    private const FACTORY_PERMISSIONS = [
        'factory.view',
        'factory.order_update',
        'factory.download',
    ];

    private const FACTORY_ROLES = [
        'laser',
        'bend',
        'powder_catting',
    ];

    /**
     * Endpoint-level dependencies required for a permission to be usable from
     * the current frontend flow. These are expanded when grants are saved.
     */
    private const DEPENDENCIES = [
        'orders.create' => [
            'clients.view',
            'factory.view',
            'pmp.view',
            'pmp_files.view',
            'pmp_group.check_remote_number',
        ],
        'orders.update' => ['orders.view'],
        'orders.delete' => ['orders.view'],
        'pmp.create' => ['pmp.view'],
        'pmp.update' => ['pmp.view'],
        'pmp_files.view' => ['pmp.view'],
        'pmp_files.upload' => ['pmp.view', 'pmp_files.view'],
        'pmp_files.delete' => ['pmp.view', 'pmp_files.view'],
        'factory.order_update' => ['factory.view'],
        'factory.download' => ['factory.view'],
    ];

    public static function forRole(?string $roleName): array
    {
        $roleName = (string) $roleName;

        if (in_array($roleName, self::FULL_ACCESS_ROLES, true)) {
            return [
                'role' => $roleName,
                'full_access' => true,
                'permissions' => PermissionMap::allSlugs(),
                'groups' => array_keys(PermissionMap::all()),
                'default_group' => null,
                'dependencies' => self::dependenciesFor(PermissionMap::allSlugs()),
            ];
        }

        if ($roleName === 'engineer') {
            return [
                'role' => $roleName,
                'full_access' => false,
                'permissions' => self::ENGINEER_PERMISSIONS,
                'groups' => [
                    'orders',
                    'pmp',
                    'pmp_files',
                    'pmp_group',
                    'clients',
                    'factory',
                ],
                'default_group' => 'orders',
                'dependencies' => self::dependenciesFor(self::ENGINEER_PERMISSIONS),
            ];
        }

        if (in_array($roleName, self::FACTORY_ROLES, true)) {
            return [
                'role' => $roleName,
                'full_access' => false,
                'permissions' => self::FACTORY_PERMISSIONS,
                'groups' => ['factory'],
                'default_group' => 'factory',
                'dependencies' => self::dependenciesFor(self::FACTORY_PERMISSIONS),
            ];
        }

        return [
            'role' => $roleName,
            'full_access' => false,
            'permissions' => [],
            'groups' => [],
            'default_group' => null,
            'dependencies' => [],
        ];
    }

    public static function allows(?string $roleName, string $slug): bool
    {
        $scope = self::forRole($roleName);

        if ($scope['full_access']) {
            return true;
        }

        return in_array($slug, $scope['permissions'], true);
    }

    public static function forRoles(array $roleNames): array
    {
        $scopes = array_map(fn ($name) => self::forRole($name), array_unique($roleNames));
        $permissions = array_values(array_unique(array_merge([], ...array_column($scopes, 'permissions'))));
        $groups = array_values(array_unique(array_merge([], ...array_column($scopes, 'groups'))));
        return ['role' => $roleNames[0] ?? '', 'roles' => array_values(array_unique($roleNames)),
            'full_access' => in_array(true, array_column($scopes, 'full_access'), true),
            'permissions' => $permissions, 'groups' => $groups, 'default_group' => $groups[0] ?? null,
            'dependencies' => self::dependenciesFor($permissions)];
    }

    public static function isFullAccess(?string $roleName): bool
    {
        return in_array((string) $roleName, self::FULL_ACCESS_ROLES, true);
    }

    public static function expandWithDependencies(?string $roleName, array $slugs): array
    {
        return self::expandForRoles([(string) $roleName], $slugs);
    }

    public static function expandForRoles(array $roles, array $slugs): array
    {
        $scope = self::forRoles($roles);
        $allowed = array_flip($scope['permissions']);
        $selected = [];

        foreach ($slugs as $slug) {
            if (isset($allowed[$slug])) {
                $selected[$slug] = true;
            }
        }

        $changed = true;
        while ($changed) {
            $changed = false;

            foreach (array_keys($selected) as $slug) {
                foreach (self::DEPENDENCIES[$slug] ?? [] as $dependency) {
                    if (isset($allowed[$dependency]) && !isset($selected[$dependency])) {
                        $selected[$dependency] = true;
                        $changed = true;
                    }
                }
            }
        }

        return array_values(array_keys($selected));
    }

    private static function dependenciesFor(array $allowedSlugs): array
    {
        $allowed = array_flip($allowedSlugs);
        $result = [];

        foreach (self::DEPENDENCIES as $slug => $dependencies) {
            if (!isset($allowed[$slug])) {
                continue;
            }

            $filtered = array_values(array_filter(
                $dependencies,
                fn (string $dependency): bool => isset($allowed[$dependency])
            ));

            if ($filtered !== []) {
                $result[$slug] = $filtered;
            }
        }

        return $result;
    }
}
