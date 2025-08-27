<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;

class ServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = [
            ['title'=>'Laser', 'slug'=>'laser', 'description'=>'Թիթեղավոր մետաղների ...', 'image'=>'services/laser.jpg', 'sort'=>1],
            ['title'=>'Bend',  'slug'=>'bend',  'description'=>'Մետաղական թիթեղների ...', 'image'=>'services/bend.jpg',  'sort'=>2],
            ['title'=>'Welding','slug'=>'welding','description'=>'Զոդման տարբեր մեթոդներ ...', 'image'=>'services/robot.jpg','sort'=>3],
            ['title'=>'Powder Coating','slug'=>'powder-coating','description'=>'Փոշեներկում ... WAGNER ...', 'image'=>'services/catt.jpg','sort'=>4],
        ];
        foreach ($items as $i) Service::updateOrCreate(['slug'=>$i['slug']], $i);
    }
}
