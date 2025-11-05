<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        // օրինակ՝ բոլոր users-ներից, որ role_id = 3 (authenticatedUser) ա,
        // դարձնել clients
        $users = User::where('role_id', 3)->get();

        foreach ($users as $user) {
            Client::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'type'   => 'physPerson',
                    'name'   => $user->name,
                    'phone'  => '+374000000',
                    'address'=> 'Test address',
                ]
            );
        }
    }
}
