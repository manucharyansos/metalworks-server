<?php

namespace Database\Seeders;

use App\Models\Work;
use App\Models\WorkImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'title_hy' => 'Laser — ցուցադրական աշխատանք',
                'title_ru' => 'Laser — демонстрационная работа',
                'title_en' => 'Laser — showcase work',
                'cover'    => 'services/laser.jpg',
                'desc_hy'  => 'Թիթեղավոր մետաղների ...',
                'desc_ru'  => 'Листовой металл ...',
                'desc_en'  => 'Sheet metal ...',
            ],
            [
                'title_hy' => 'Bend — ցուցադրական աշխատանք',
                'title_ru' => 'Bend — демонстрационная работа',
                'title_en' => 'Bend — showcase work',
                'cover'    => 'services/bend.jpg',
                'desc_hy'  => 'Մետաղական թիթեղների ...',
                'desc_ru'  => 'Листовой металл ...',
                'desc_en'  => 'Sheet metal ...',
            ],
            [
                'title_hy' => 'Welding — ցուցադրական աշխատանք',
                'title_ru' => 'Welding — демонстрационная работа',
                'title_en' => 'Welding — showcase work',
                'cover'    => 'services/robot.jpg',
                'desc_hy'  => 'Զոդման տարբեր մեթոդներ ...',
                'desc_ru'  => 'Разные методы сварки ...',
                'desc_en'  => 'Various welding methods ...',
            ],
            [
                'title_hy' => 'Powder Coating — ցուցադրական աշխատանք',
                'title_ru' => 'Powder Coating — демонстрационная работа',
                'title_en' => 'Powder Coating — showcase work',
                'cover'    => 'services/catt.jpg',
                'desc_hy'  => 'Փոշեներկում ... WAGNER ...',
                'desc_ru'  => 'Порошковая окраска ... WAGNER ...',
                'desc_en'  => 'Powder coating ... WAGNER ...',
            ],
        ];

        foreach ($items as $i) {
            $source = $i['cover'];
            $this->ensureFileOrPlaceholder($source);

            // locale-wise slugs (auto)
            $slugHy = Str::slug(Str::before($i['title_hy'], ' —')) ?: Str::random(8);
            $slugRu = Str::slug(Str::before($i['title_ru'], ' —')) ?: $slugHy.'-ru';
            $slugEn = Str::slug(Str::before($i['title_en'], ' —')) ?: $slugHy.'-en';

            // find or new by hy slug
            $work = Work::where("slug->hy", $slugHy)->first() ?: new Work();

            // set translations
            $work->setTranslations('title', [
                'hy' => $i['title_hy'],
                'ru' => $i['title_ru'],
                'en' => $i['title_en'],
            ]);
            $work->setTranslations('description', [
                'hy' => $i['desc_hy'],
                'ru' => $i['desc_ru'],
                'en' => $i['desc_en'],
            ]);
            $work->setTranslations('slug', [
                'hy' => $slugHy,
                'ru' => $slugRu,
                'en' => $slugEn,
            ]);

            $work->image        = $source;
            $work->is_published = true;
            $work->sort_order   = 100;
            $work->save();

            // 3 gallery images
            for ($g = 1; $g <= 3; $g++) {
                $target = "works/gallery/{$slugHy}-{$g}.jpg";
                $this->copyOrPlaceholder($source, $target);

                WorkImage::updateOrCreate(
                    ['work_id' => $work->id, 'path' => $target],
                    []
                );
            }
        }
    }

    protected function ensureFileOrPlaceholder(?string $path): void
    {
        if (!$path) return;

        if (!Storage::disk('public')->exists($path)) {
            $fromPublic = public_path($path);
            if (is_file($fromPublic)) {
                $this->putFromFilesystem($fromPublic, $path);
            } else {
                $this->putPlaceholder($path);
            }
        }
    }

    protected function copyOrPlaceholder(string $from, string $to): void
    {
        $dir = dirname($to);
        if (!Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->makeDirectory($dir);
        }

        if (Storage::disk('public')->exists($from)) {
            Storage::disk('public')->put($to, Storage::disk('public')->get($from));
            return;
        }

        $fromPublic = public_path($from);
        if (is_file($fromPublic)) {
            $this->putFromFilesystem($fromPublic, $to);
        } else {
            $this->putPlaceholder($to);
        }
    }

    protected function putFromFilesystem(string $absolute, string $destPath): void
    {
        $dir = dirname($destPath);
        if (!Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->makeDirectory($dir);
        }
        Storage::disk('public')->put($destPath, file_get_contents($absolute));
    }

    protected function putPlaceholder(string $path): void
    {
        $dir = dirname($path);
        if (!Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->makeDirectory($dir);
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAEklEQVR4nGNgYGBgYAAAAAQAAVzKkS0AAAAASUVORK5CYII=');
        Storage::disk('public')->put($path, $png);
    }
}
