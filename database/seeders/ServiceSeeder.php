<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'title'       => ['hy'=>'Ինժիներական նախագծում','ru'=>'Инженерное проектирование','en'=>'Engineering'],
                'slug'        => ['hy'=>'engineering','ru'=>'engineering-ru','en'=>'engineering'],
                'description' => [
                    'hy'=>'Մետաղական կոնստրուկցիաների, պատրաստվածքների նախագծում և մոդելավորում',
                    'ru'=>'Проектирование и моделирование металлических конструкций и изделий',
                    'en'=>'Design and modeling of metal structures and parts'
                ],
                'image'       => 'services/laser.jpg',
                'video'       => null,
                'video_poster'=> null,
            ],
            [
                'title'       => ['hy'=>'Լազերային կտրում','ru'=>'Лазерная резка','en'=>'Laser cutting'], // ← ճիշտ 'en'
                'slug'        => ['hy'=>'laser-cutting','ru'=>'lazer-rezka','en'=>'laser-cutting'],
                'description' => [
                    'hy'=>'Թիթեղավոր մետաղների լազերային կտրում (մինչև 30 մմ)… պրոֆիլների լազերային կտրում (մինչև 240 մմ)…',
                    'ru'=>'Лазерная резка листового металла (до 30 мм)… резка профилей (до 240 мм)…',
                    'en'=>'Laser cutting of sheet metal (up to 30mm)… profiles (up to 240mm)…'
                ],
                'image'       => 'services/laser.jpg',
                'video'       => null,
                'video_poster'=> null,
            ],
            [
                'title'       => ['hy'=>'Ճկում','ru'=>'Гибка','en'=>'Bending'],
                'slug'        => ['hy'=>'bending','ru'=>'gibka','en'=>'bending'],
                'description' => [
                    'hy'=>'Պրոֆիլային խողովակների ու թիթեղների ճկում (վալց, մամլիչ)…',
                    'ru'=>'Гибка профильных труб и листов (вальцы, пресс)…',
                    'en'=>'Bending of tubes and sheets (rolls, press)…'
                ],
                'image'       => 'services/bend.jpg',
                'video'       => null,
                'video_poster'=> null,
            ],
            [
                'title'       => ['hy'=>'Զոդում','ru'=>'Сварка','en'=>'Welding'],
                'slug'        => ['hy'=>'welding','ru'=>'svarka','en'=>'welding'],
                'description' => [
                    'hy'=>'Զոդում իներտ միջավայրում (Laser, MIG, MIG-MAG, TIG, MMA)',
                    'ru'=>'Сварка в инертной среде (Laser, MIG, MIG-MAG, TIG, MMA)',
                    'en'=>'Welding in inert environment (Laser, MIG, MIG-MAG, TIG, MMA)'
                ],
                'image'       => 'services/robot.jpg',
                'video'       => null,
                'video_poster'=> null,
            ],
            [
                'title'       => ['hy'=>'Փոշեներկում','ru'=>'Порошковая покраска','en'=>'Powder coating'],
                'slug'        => ['hy'=>'powder-coating','ru'=>'poroshkovaya-pokraska','en'=>'powder-coating'],
                'description' => [
                    'hy'=>'Էլեկտրոստատիկ նստեցմամբ փոշեներկում WAGNER սարքավորումներով',
                    'ru'=>'Порошковая покраска электростатическим напылением WAGNER',
                    'en'=>'Electrostatic powder coating with WAGNER equipment'
                ],
                'image'       => 'services/catt.jpg',
                'video'       => null,
                'video_poster'=> null,
            ],
        ];

        foreach ($items as $i) {
            $service = Service::firstOrNew(['slug->hy' => $i['slug']['hy']]);
            $service->setTranslations('title', $i['title']);
            $service->setTranslations('slug', $i['slug']);
            $service->setTranslations('description', $i['description']);
            $service->image = $i['image'] ?? null;
            $service->video = $i['video'] ?? null;
            $service->video_poster = $i['video_poster'] ?? null;

            $service->save();
        }
    }
}
