<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Orders permissions
        Permission::firstOrCreate(
            ['slug' => 'orders.view_all'],
            ['name' => 'Տեսնել բոլոր պատվերները', 'group' => 'orders']
        );

        Permission::firstOrCreate(
            ['slug' => 'orders.view_own'],
            ['name' => 'Տեսնել միայն սեփական պատվերները', 'group' => 'orders']
        );

        Permission::firstOrCreate(
            ['slug' => 'orders.mark_done'],
            ['name' => 'Նշել պատվերը որպես ավարտված', 'group' => 'orders']
        );

        // Clients permissions
        Permission::firstOrCreate(
            ['slug' => 'clients.view'],
            ['name' => 'Դիտել հաճախորդներին', 'group' => 'clients']
        );

        Permission::firstOrCreate(
            ['slug' => 'clients.edit'],
            ['name' => 'Խմբագրել հաճախորդներին', 'group' => 'clients']
        );

         Permission::firstOrCreate(
             ['slug' => 'permissions.manage'],
             ['name' => 'Կառավարել թույլտվությունները', 'group' => 'system']
         );
    }
}
