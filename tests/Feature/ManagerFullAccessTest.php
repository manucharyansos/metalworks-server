<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionScope;
use Tests\TestCase;

class ManagerFullAccessTest extends TestCase
{
    public function test_manager_has_every_business_permission_without_individual_assignments(): void
    {
        $manager = new User();
        $manager->setRelation('role', new Role(['name' => 'manager']));

        foreach ([
            'workers.view',
            'workers.create',
            'workers.update',
            'workers.delete',
            'clients.view',
            'clients.create',
            'clients.update',
            'clients.delete',
            'materials.view',
            'materials.create',
            'materials.update',
            'materials.delete',
            'material_categories.view',
            'orders.view',
            'factory.view',
            'pmp.view',
        ] as $permission) {
            $this->assertTrue(
                $manager->hasPermission($permission),
                "Manager should have permission [{$permission}]"
            );
        }
    }


    public function test_employee_permission_scopes_match_their_workspaces(): void
    {
        foreach ([
            'orders.view',
            'orders.create',
            'pmp.view',
            'pmp_files.view',
            'clients.view',
            'factory.view',
        ] as $permission) {
            $this->assertTrue(PermissionScope::allows('engineer', $permission));
        }

        foreach ([
            'workers.view',
            'materials.view',
            'clients.create',
            'factory.order_update',
        ] as $permission) {
            $this->assertFalse(PermissionScope::allows('engineer', $permission));
        }

        foreach (['laser', 'bend', 'powder_catting'] as $role) {
            $this->assertTrue(PermissionScope::allows($role, 'factory.view'));
            $this->assertTrue(PermissionScope::allows($role, 'factory.order_update'));
            $this->assertTrue(PermissionScope::allows($role, 'factory.download'));
            $this->assertFalse(PermissionScope::allows($role, 'orders.view'));
        }
    }

    public function test_order_creation_expands_required_endpoint_permissions(): void
    {
        $permissions = PermissionScope::expandWithDependencies('engineer', [
            'orders.create',
        ]);

        foreach ([
            'orders.create',
            'clients.view',
            'factory.view',
            'pmp.view',
            'pmp_files.view',
            'pmp_group.check_remote_number',
        ] as $required) {
            $this->assertContains($required, $permissions);
        }
    }

    public function test_regular_employee_does_not_gain_implicit_full_access(): void
    {
        $employee = new User();
        $employee->setRelation('role', new Role(['name' => 'engineer']));

        $this->assertFalse($employee->hasPermission('workers.create'));
        $this->assertFalse($employee->hasPermission('clients.create'));
        $this->assertFalse($employee->hasPermission('materials.create'));
    }
}
