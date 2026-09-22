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
            ];
        }

        if (in_array($roleName, self::FACTORY_ROLES, true)) {
            return [
                'role' => $roleName,
                'full_access' => false,
                'permissions' => self::FACTORY_PERMISSIONS,
                'groups' => ['factory'],
                'default_group' => 'factory',
            ];
        }

        return [
            'role' => $roleName,
            'full_access' => false,
            'permissions' => [],
            'groups' => [],
            'default_group' => null,
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

    public static function isFullAccess(?string $roleName): bool
    {
        return in_array((string) $roleName, self::FULL_ACCESS_ROLES, true);
    }
}
