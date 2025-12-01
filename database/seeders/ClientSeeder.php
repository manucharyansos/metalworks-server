<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $clients = [
            [
                'name'         => 'Արմեն Հովհաննիսյան',
                'email'        => 'armen@client.am',
                'phone'        => '+37498123456',
                'address'      => 'Երևան, Կոմիտասի 49',
                'type'         => 'physPerson',
                'last_name'    => 'Հովհաննիսյան',
                'second_phone' => '+37477123456',
            ],
            [
                'name'    => 'Աննա Գրիգորյան',
                'email'   => 'anna@client.am',
                'phone'   => '+37491123456',
                'address' => 'Գյումրի, Շիրակի 12',
                'type'    => 'physPerson',
            ],
            [
                'name'      => 'Սարգիս Մարտիրոսյան',
                'email'     => 'sargis@client.am',
                'phone'     => '+37493123456',
                'address'   => 'Վանաձոր, Տիգրան Մեծ 88',
                'type'      => 'physPerson',
                'last_name' => 'Մարտիրոսյան',
            ],
            [
                'name'    => 'Մարիամ Պետրոսյան',
                'email'   => 'mariam@client.am',
                'phone'   => '+37499111222',
                'address' => 'Երևան, Մաշտոցի 25',
                'type'    => 'physPerson',
                'last_name' => 'Պետրոսյան',
            ],

            [
                'name'         => 'Գևորգ Սարգսյան',
                'email'        => 'info@haytech.am',
                'phone'        => '+37410567890',
                'address'      => 'Երևան, Հանրապետության 77',
                'type'         => 'legalEntity',
                'company_name' => 'ՀայՏեխ ՍՊԸ',
                'AVC'          => '01234567',
                'accountant'   => 'Անահիտ Հակոբյան',
            ],
            [
                'name'         => 'Կարեն Պետրոսյան',
                'email'        => 'contact@armgroup.am',
                'phone'        => '+37411223344',
                'address'      => 'Երևան, Կիևյան 16',
                'type'         => 'legalEntity',
                'company_name' => 'ԱրմԳրուպ ՍՊԸ',
                'AVC'          => '02345678',
                'accountant'   => 'Սոնա Մկրտչյան',
            ],
            [
                'name'         => 'Մերի Խաչատրյան',
                'email'        => 'hr@smartlab.am',
                'phone'        => '+37460500600',
                'address'      => 'Երևան, Տերյան 105',
                'type'         => 'legalEntity',
                'company_name' => 'ՍմարթԼաբ ՍՊԸ',
                'AVC'          => '03456789',
                'accountant'   => 'Էդուարդ Ավետիսյան',
            ],
        ];

        foreach ($clients as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name'     => $data['name'],
                    'password' => Hash::make('password'),
                    'role_id'  => 3,
                ]
            );

            $clientData = [
                'user_id'      => $user->id,
                'name'         => $data['name'],
                'phone'        => $data['phone'],
                'address'      => $data['address'] ?? null,
                'type'         => $data['type'],
                'last_name'    => $data['last_name'] ?? null,
                'second_phone' => $data['second_phone'] ?? null,
                'company_name' => $data['company_name'] ?? null,
                'AVC'          => $data['AVC'] ?? null,
                'accountant'   => $data['accountant'] ?? null,
            ];

            Client::updateOrCreate(
                ['user_id' => $user->id],
                $clientData
            );
        }

        $this->command->info('Հաջողությամբ ստեղծվեց ' . count($clients) . ' հաճախորդ (ֆիզ. + իրավաբ.)');
    }
}
