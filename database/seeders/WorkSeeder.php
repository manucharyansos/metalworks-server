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
            ['title'=>'Laser — ցուցադրական աշխատանք',   'cover'=>'services/laser.jpg',  'desc'=>'Թիթեղավոր մետաղների ...'],
            ['title'=>'Bend — ցուցադրական աշխատանք',    'cover'=>'services/bend.jpg',   'desc'=>'Մետաղական թիթեղների ...'],
            ['title'=>'Welding — ցուցադրական աշխատանք', 'cover'=>'services/robot.jpg',  'desc'=>'Զոդման տարբեր մեթոդներ ...'],
            ['title'=>'Powder Coating — ցուցադրական աշխատանք','cover'=>'services/catt.jpg','desc'=>'Փոշեներկում ... WAGNER ...'],
        ];

        foreach ($items as $i) {
            $source = $i['cover'];
            $this->ensureFileOrPlaceholder($source);

            $slug = Str::slug(Str::before($i['title'], ' —')) . '-' . Str::random(5);

            $work = Work::updateOrCreate(
                ['slug' => $slug],
                [
                    'title'         => $i['title'],
                    'description'   => $i['desc'],
                    'image'         => $source,
                    'is_published'  => true,
                    'sort_order'    => 100,
                ]
            );

            for ($g = 1; $g <= 3; $g++) {
                $target = "works/gallery/{$slug}-{$g}.jpg";
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
            $this->putPlaceholder($path);
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
        } else {
            $this->putPlaceholder($to);
        }
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
