<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This is Metalworks' production catalog. Other companies keep their own
        // workshops; existing IDs, staff assignments, tasks and file sections stay intact.
        $companyId = DB::table('companies')->where('slug', 'metalworks')->value('id');
        if (!$companyId) return;

        DB::transaction(function () use ($companyId) {
            $workshops = [
                'DXF' => ['Լազերային կտրում՝ լիստեր', ['Laser cutting', 'Լազերային կտրում', 'Լազերային կտրման', 'Лазерная резка']],
                'IQS' => ['Լազերային կտրում՝ խողովակներ', ['Laser', 'Լազեր', 'Лазер']],
                'DLD' => ['Կռում', ['Bend', 'Bending', 'Կռման', 'Ճկում', 'Гибка']],
                'POWDER' => ['Փոշեներկում', ['Powder', 'Powder coating', 'Порошковая покраска']],
                'WELD' => ['Զոդում', ['Weld', 'Welding', 'Сварка']],
                'ROLL' => ['Վալց', ['Roll', 'Rolling', 'Վալցում', 'Вальцовка']],
            ];
            foreach ($workshops as $code => [$name, $legacyNames]) {
                $query = fn () => DB::table('factories')->where('company_id', $companyId);
                $factory = $query()->where('value', $code)->first();
                // Reuse a previously added real workshop instead of duplicating it.
                $factory ??= $query()->where('is_workshop', true)->where('is_reference', false)
                    ->whereIn('name', [$name, ...$legacyNames])->orderBy('id')->first();
                if ($factory) {
                    $data = ['is_workshop' => true, 'is_reference' => false];
                    if (in_array($factory->name, $legacyNames, true)) $data['name'] = $name;
                    $query()->where('id', $factory->id)->update($data);
                } else {
                    $query()->insert(['company_id' => $companyId, 'value' => $code, 'name' => $name,
                        'is_workshop' => true, 'is_reference' => false, 'is_active' => true,
                        'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // New workshops may already have staff, files or tasks. Keep their history.
    }
};
