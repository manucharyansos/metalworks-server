<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Default project file sections show their short codes again, as before the
     * workshop/file-section split (IQS is shown as IGS, its file format).
     * Only names that were assigned automatically are replaced; a name an
     * administrator typed is kept, and files, formats and IDs are unchanged.
     */
    private const NAMES = [
        'SW' => ['SW', ['CAD նախագծեր', 'CAD projects', 'CAD-проекты', 'Engineering', 'SolidWorks']],
        'DLD' => ['DLD', ['Կռման գծագրեր', 'Bending drawings', 'Чертежи гибки']],
        'DXF' => ['DXF', ['DXF գծագրեր', 'DXF drawings', 'DXF-чертежи']],
        'IQS' => ['IGS', ['3D գծագրեր', '3D drawings', '3D чертежи', '3D-чертежи', 'IQS']],
        'INFO' => ['INFO', ['Տեղեկատու ֆայլեր', 'Reference files', 'Справочные файлы', 'Informal']],
        'PDF' => ['PDF', ['PDF փաստաթղթեր', 'PDF documents', 'PDF-документы']],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('file_categories')) return;

        DB::transaction(function () {
            foreach (self::NAMES as $code => [$name, $defaults]) {
                DB::table('file_categories')->where('code', $code)->whereIn('name', $defaults)
                    ->update(['name' => $name, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Section names are display data; keep whatever is current on rollback.
    }
};
