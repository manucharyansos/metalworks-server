<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Վերցնում ենք roles
        $adminRole   = Role::where('name', 'admin')->first();
        $managerRole = Role::where('name', 'manager')->first();
        $engineerRole = Role::where('name', 'engineer')->first();
        $laserRole   = Role::where('name', 'laser')->first();

        // Վերցնում ենք permissions
        $viewAllOrders = Permission::where('slug', 'orders.view_all')->first();
        $viewOwnOrders = Permission::where('slug', 'orders.view_own')->first();
        $markOrderDone = Permission::where('slug', 'orders.mark_done')->first();
        $viewClients   = Permission::where('slug', 'clients.view')->first();
        $editClients   = Permission::where('slug', 'clients.edit')->first();

        // Admin → ունի բոլոր permissions
        if ($adminRole) {
            $allPermissions = Permission::all();
            $adminRole->permissions()->sync($allPermissions->pluck('id')->toArray());
        }

        // Manager → տեսնում է բոլոր պատվերները և կարող է խմբագրել հաճախորդներին
        if ($managerRole) {
            $managerRole->permissions()->sync([
                optional($viewAllOrders)->id,
                optional($viewClients)->id,
                optional($editClients)->id,
                // եթե ուզես՝ ավելացրու նաև orders.mark_done կամ այլ permissions
            ]);
        }

        // Engineer → տեսնում է միայն իր պատվերները (բազային)
        if ($engineerRole) {
            $engineerRole->permissions()->sync([
                optional($viewOwnOrders)->id,
            ]);
        }

        // Laser → կարող է նշել, որ պատվերը ավարտված է
        if ($laserRole) {
            $laserRole->permissions()->sync([
                optional($markOrderDone)->id,
                // կարող ես ավելացնել նաև viewOwnOrders, եթե ուզում ես որ տեսնի իրեն վերաբերող պատվերը
                optional($viewOwnOrders)->id,
            ]);
        }
    }
}
