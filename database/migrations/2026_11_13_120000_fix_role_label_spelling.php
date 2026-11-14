<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Role labels the seeder once wrote misspelled; a label someone already changed stays as it is. */
    private const LABELS = [
        'engineer' => ['Ինժիներ', 'Ինժեներ'],
        'laser' => ['Լազերաին կտրում', 'Լազերային կտրում'],
    ];

    public function up(): void
    {
        foreach (self::LABELS as $name => [$wrong, $right]) {
            DB::table('roles')->where('name', $name)->where('value', $wrong)->update(['value' => $right]);
        }
    }

    public function down(): void
    {
        foreach (self::LABELS as $name => [$wrong, $right]) {
            DB::table('roles')->where('name', $name)->where('value', $right)->update(['value' => $wrong]);
        }
    }
};
