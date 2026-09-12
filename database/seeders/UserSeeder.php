<?php

namespace Database\Seeders;

use App\Models\Factory;
use App\Models\Role;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'admin'           => Role::firstWhere('name', 'admin'),
            'manager'         => Role::firstWhere('name', 'manager'),
            'engineer'        => Role::firstWhere('name', 'engineer'),
            'bend'            => Role::firstWhere('name', 'bend'),
            'laser'           => Role::firstWhere('name', 'laser'),
            'powder_catting'  => Role::firstWhere('name', 'powder_catting'),
        ];

        if (app()->environment('production')) {
            $this->seedPrivilegedUsers($roles);
            return;
        }

        $factories = [
            'bend'            => Factory::firstWhere('name', 'Bend'),
            'laser_cutting'   => Factory::firstWhere('name', 'Laser cutting'),
            'laser'           => Factory::firstWhere('name', 'Laser'),
            'powder'          => Factory::firstWhere('name', 'Informal'),
        ];

        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'     => 'Admin User',
                'password' => Hash::make('password'),
                'role_id'  => $roles['admin']?->id,
            ]
        );

        $managers = [
            ['Manager 1', 'manager@metalworks.am'],
            ['Manager 2', 'manager2@metalworks.am'],
        ];

        foreach ($managers as [$name, $email]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name'     => $name,
                    'password' => Hash::make('password'),
                    'role_id'  => $roles['manager']?->id,
                ]
            );
        }

        $engineers = [
            ['Հայկ Գրիգորյան', 'engineering@metalworks.am'],
            ['Անի Հովհաննիսյան', 'engineer2@metalworks.am'],
        ];

        foreach ($engineers as [$name, $email]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name'     => $name,
                    'password' => Hash::make('password'),
                    'role_id'  => $roles['engineer']?->id,
                ]
            );
        }

        $workers = [
            ['Աշոտ Մարտիրոսյան', 'bend@metalworks.am', $roles['bend'], $factories['bend'], 'Մարտիրոսյան', '+37498111222'],
            ['Գոռ Գևորգյան', 'bend2@metalworks.am', $roles['bend'], $factories['bend'], 'Գևորգյան', '+37498122334'],

            ['Սարգիս Խաչատրյան', 'catting@metalworks.am', $roles['powder_catting'], $factories['powder'], 'Խաչատրյան', '+37477123456'],
            ['Լևոն Հակոբյան', 'catting2@metalworks.am', $roles['powder_catting'], $factories['powder'], 'Հակոբյան', '+37477134567'],

            ['Դավիթ Պետրոսյան', 'laser@metalworks.am', $roles['laser'], $factories['laser_cutting'], 'Պետրոսյան', '+37493111222'],
            ['Վահե Սարգսյան', 'laser2@metalworks.am', $roles['laser'], $factories['laser_cutting'], 'Սարգսյան', '+37493122334'],
        ];

        foreach ($workers as $w) {
            [$name, $email, $role, $factory, $lastName, $phone] = $w;

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'       => $name,
                    'password'   => Hash::make('password'),
                    'role_id'    => $role?->id,
                    'factory_id' => $factory?->id,
                ]
            );

            Worker::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'last_name'    => $lastName,
                    'phone'        => $phone,
                    'second_phone' => null,
                    'address'      => 'Երևան, ' . ($factory?->name ?? 'Անհայտ'),
                ]
            );
        }

        $this->command?->info('Հաջողությամբ ստեղծվեցին Admin, Managers, Engineers և Workers (ընդհանուր ' . User::count() . ' օգտատեր)');
    }

    private function seedPrivilegedUsers(array $roles): void
    {
        $accounts = [
            'admin' => config('privileged_users.admin'),
            'manager' => config('privileged_users.manager'),
        ];

        foreach ($accounts as $roleName => $account) {
            $email = trim((string) ($account['email'] ?? ''));
            $name = trim((string) ($account['name'] ?? ''));
            $password = (string) ($account['password'] ?? '');
            $role = $roles[$roleName] ?? null;

            if ($email === '') {
                $this->command?->warn(strtoupper($roleName) . '_EMAIL is empty; account was not created.');
                continue;
            }

            if (! $role) {
                $this->command?->error("Role '{$roleName}' was not found; {$email} was not created or updated.");
                continue;
            }

            $user = User::firstWhere('email', $email);

            if (! $user) {
                if ($password === '') {
                    $this->command?->warn(strtoupper($roleName) . "_INITIAL_PASSWORD is empty; {$email} was not created.");
                    continue;
                }

                $user = new User();
                $user->email = $email;
                $user->password = Hash::make($password);
            }

            $user->name = $name !== '' ? $name : ucfirst($roleName);
            $user->role_id = $role->id;
            $user->save();

            $this->command?->info(ucfirst($roleName) . " account ensured: {$email}");
        }
    }
}
