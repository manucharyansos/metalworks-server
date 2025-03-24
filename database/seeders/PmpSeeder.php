<?php

namespace Database\Seeders;

use App\Models\Pmp;
use App\Models\RemoteNumber;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PmpSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pmp1 = Pmp::create(["group" => "001", "group_name" => "Աթոռ", "admin_confirmation" => false]);
        $pmp2 = Pmp::create(["group" => "002", "group_name" => "Սեղան", "admin_confirmation" => false]);
        $pmp3 = Pmp::create(["group" => "003", "group_name" => "Սեյֆ", "admin_confirmation" => false]);
        $pmp4 = Pmp::create(["group" => "004", "group_name" => "Մանղալ", "admin_confirmation" => false]);
        $pmp5 = Pmp::create(["group" => "005", "group_name" => "Աստիճան", "admin_confirmation" => false]);
        $pmp6 = Pmp::create(["group" => "006", "group_name" => "Դուռ", "admin_confirmation" => false]);
        $pmp7 = Pmp::create(["group" => "007", "group_name" => "Ռեշոտկա", "admin_confirmation" => false]);

        // Create RemoteNumber for each Pmp
        RemoteNumber::create(["remote_number" => "01", "pmp_id" => $pmp1->id, 'remote_number_name' => 'Աթոռ ոտերով']);
        RemoteNumber::create(["remote_number" => "02", "pmp_id" => $pmp2->id, 'remote_number_name' => 'Սեղան ոտերով']);
        RemoteNumber::create(["remote_number" => "03", "pmp_id" => $pmp3->id, 'remote_number_name' => 'Սեյֆ 1800 մմ']);
        RemoteNumber::create(["remote_number" => "04", "pmp_id" => $pmp4->id, 'remote_number_name' => 'Մանղալ տանիքով']);
        RemoteNumber::create(["remote_number" => "05", "pmp_id" => $pmp5->id, 'remote_number_name' => 'Աստիճան ուղիղ']);
        RemoteNumber::create(["remote_number" => "06", "pmp_id" => $pmp6->id, 'remote_number_name' => 'Դուռ 1500 մմ']);
        RemoteNumber::create(["remote_number" => "07", "pmp_id" => $pmp7->id, 'remote_number_name' => 'Ռեշոտկա']);
    }
}
