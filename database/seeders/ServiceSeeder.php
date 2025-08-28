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
            [
                'title'=>'Engineering',
                'slug'=>'Ինժիներական նախագծում',
                'description'=>'Մետաղական կոնստրուկցիաների, պատրաստվածքների նախագծում և մոդելավորում',
                'image'=>'services/laser.jpg',
                'sort'=>1
            ],
            [
                'title'=>'Laser',
                'slug'=>'Լազերային կտրում',
                'description'=>'Թիթեղավոր մետաղների լազերային կտրում (մինչև 30 մմ հաստությամբ) Մետաղական պրոֆիլների լազերային կտրում (մինչև 240 մմ տրամագծով) ՝ ուղղանկյուն, կլոր, օվալաձև խողովակներ, հեծաններ, երկտավրեր, անկյունակներ, տավրեր և այլն հատումներով պրոֆիլներ',
                'image'=>'services/laser.jpg',
                'sort'=>1
            ],
            [
                'title'=>'Bend',
                'slug'=>'Ճկում',
                'description'=>'Պրոֆիլային խողովակների ճկում Թիթեղավոր մետաղների ճկում քառագլդոնի միջոցով (вальц)',
                'image'=>'services/bend.jpg',
                'sort'=>2
            ],
            [
                'title'=>'Welding',
                'slug'=>'Զոդում',
                'description'=>'Զոդման տարբեր մեթոդներ իներտ միջավայրում (Laser, MIG, MIG-MAG, TIG, MMA)',
                'image'=>'services/robot.jpg',
                'sort'=>3
            ],
            [
                'title'=>'Powder Coating',
                'slug'=>'Փոշեներկում',
                'description'=>'Փոշեներկում էլեկտրոստատիկ նստեցման մեթոդով «WAGNER» առաջատար ընկերության սարքավորումներով',
                'image'=>'services/catt.jpg',
                'sort'=>4
            ],
        ];
        foreach ($items as $i) Service::updateOrCreate(['slug'=>$i['slug']], $i);
    }
}
