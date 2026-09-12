<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'admin' => Role::firstWhere('name', 'admin'),
            'manager' => Role::firstWhere('name', 'manager'),
        ];

        $this->seedPrivilegedUsers($roles);
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
