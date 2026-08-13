<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleTableSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'admin' => 'Ադմին',
            'guestUser' => 'Չգրանցված օգտատեր',
            'authenticatedUser' => 'Գրանցված օգտատեր',
            'manager' => 'Մենեջեր',
            'bend' => 'Կռում',
            'laser' => 'Լազերաին կտրում',
            'powder_catting' => 'Փոշեներկում',
            'engineer' => 'Ինժիներ',
        ];

        foreach ($roles as $name => $value) {
            Role::updateOrCreate(
                ['name' => $name],
                ['value' => $value]
            );
        }
    }
}
