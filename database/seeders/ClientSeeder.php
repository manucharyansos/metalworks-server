<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        // Clients with associated users
        $user1 = User::where('email', 'admin@example.com')->first();
        $user2 = User::where('email', 'creator@example.com')->first();

        Client::create([
            'name' => 'Client Admin',
            'number' => '1234567890',
            'AVC' => 'AVC123',
            'group' => 'Group A',
            'VAT_payer' => true,
            'legal_address' => '123 Legal St',
            'valid_address' => '456 Valid St',
            'VAT_of_the_manager' => 'VAT123456',
            'leadership_position' => 'CEO',
            'accountants_VAT' => 'ACC123456',
            'accountant_position' => 'Head Accountant',
            'registration_of_the_individual' => 'REG12345',
            'type_of_ID_card' => 'Passport',
            'passport_number' => 'P123456',
            'email_address' => 'client1@example.com',
            'user_id' => $user1->id, // Associated user
            'contract' => 'Contract 001',
            'contract_date' => now(),
            'sales_discount_percentage' => '10%',
        ]);

        Client::create([
            'name' => 'Client Creator',
            'number' => '0987654321',
            'AVC' => 'AVC456',
            'group' => 'Group B',
            'VAT_payer' => false,
            'legal_address' => '789 Legal Ave',
            'valid_address' => '101 Valid Ave',
            'VAT_of_the_manager' => 'VAT654321',
            'leadership_position' => 'CFO',
            'accountants_VAT' => 'ACC654321',
            'accountant_position' => 'Accountant',
            'registration_of_the_individual' => 'REG54321',
            'type_of_ID_card' => 'National ID',
            'passport_number' => 'P654321',
            'email_address' => 'client2@example.com',
            'user_id' => $user2->id, // Associated user
            'contract' => 'Contract 002',
            'contract_date' => now(),
            'sales_discount_percentage' => '15%',
        ]);

        // Clients without associated users (manually added by admin)
        Client::create([
            'name' => 'Manual Client 1',
            'number' => '9876543210',
            'AVC' => 'AVC789',
            'group' => 'Group C',
            'VAT_payer' => true,
            'legal_address' => '101 Admin St',
            'valid_address' => '202 Admin Ave',
            'VAT_of_the_manager' => 'VAT987654',
            'leadership_position' => 'Manager',
            'accountants_VAT' => 'ACC987654',
            'accountant_position' => 'Lead Accountant',
            'registration_of_the_individual' => 'REG98765',
            'type_of_ID_card' => 'Passport',
            'passport_number' => 'P987654',
            'email_address' => 'client3@example.com',
            'user_id' => null, // No associated user
            'contract' => 'Contract 003',
            'contract_date' => now(),
            'sales_discount_percentage' => '20%',
        ]);
    }
}
