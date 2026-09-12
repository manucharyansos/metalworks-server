<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
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

    public function test_regular_employee_does_not_gain_implicit_full_access(): void
    {
        $employee = new User();
        $employee->setRelation('role', new Role(['name' => 'engineer']));

        $this->assertFalse($employee->hasPermission('workers.create'));
        $this->assertFalse($employee->hasPermission('clients.create'));
        $this->assertFalse($employee->hasPermission('materials.create'));
    }
}
