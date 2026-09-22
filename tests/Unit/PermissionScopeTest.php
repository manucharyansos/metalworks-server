<?php

namespace Tests\Unit;

use App\Support\PermissionScope;
use PHPUnit\Framework\TestCase;

class PermissionScopeTest extends TestCase
{
    public function test_engineer_sees_only_engineering_and_required_lookup_permissions(): void
    {
        $scope = PermissionScope::forRole('engineer');

        $this->assertFalse($scope['full_access']);
        $this->assertSame('orders', $scope['default_group']);

        foreach ([
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
        ] as $slug) {
            $this->assertTrue(PermissionScope::allows('engineer', $slug), $slug);
        }

        foreach ([
            'workers.view',
            'workers.create',
            'materials.view',
            'clients.create',
            'factory.order_update',
            'factory.download',
        ] as $slug) {
            $this->assertFalse(PermissionScope::allows('engineer', $slug), $slug);
        }
    }

    public function test_factory_roles_only_receive_factory_workspace_permissions(): void
    {
        foreach (['laser', 'bend', 'powder_catting'] as $role) {
            $this->assertTrue(PermissionScope::allows($role, 'factory.view'));
            $this->assertTrue(PermissionScope::allows($role, 'factory.order_update'));
            $this->assertTrue(PermissionScope::allows($role, 'factory.download'));

            $this->assertFalse(PermissionScope::allows($role, 'orders.view'));
            $this->assertFalse(PermissionScope::allows($role, 'pmp.view'));
            $this->assertFalse(PermissionScope::allows($role, 'clients.view'));
        }
    }

    public function test_admin_and_manager_are_full_access_roles(): void
    {
        $this->assertTrue(PermissionScope::isFullAccess('admin'));
        $this->assertTrue(PermissionScope::isFullAccess('manager'));
        $this->assertTrue(PermissionScope::allows('admin', 'workers.delete'));
        $this->assertTrue(PermissionScope::allows('manager', 'materials.create'));
    }

    public function test_permission_dependencies_are_expanded_for_real_frontend_flows(): void
    {
        $engineer = PermissionScope::expandWithDependencies('engineer', [
            'orders.create',
            'pmp_files.upload',
        ]);

        foreach ([
            'orders.create',
            'clients.view',
            'factory.view',
            'pmp.view',
            'pmp_files.view',
            'pmp_files.upload',
            'pmp_group.check_remote_number',
        ] as $slug) {
            $this->assertContains($slug, $engineer, $slug);
        }

        $factory = PermissionScope::expandWithDependencies('laser', [
            'factory.download',
        ]);

        $this->assertContains('factory.view', $factory);
        $this->assertContains('factory.download', $factory);
        $this->assertNotContains('orders.view', $factory);
    }

    public function test_unknown_employee_role_gets_no_individual_permissions(): void
    {
        $scope = PermissionScope::forRole('unknown');

        $this->assertFalse($scope['full_access']);
        $this->assertSame([], $scope['permissions']);
        $this->assertSame([], $scope['groups']);
    }
}
