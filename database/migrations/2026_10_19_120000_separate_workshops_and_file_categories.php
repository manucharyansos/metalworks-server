<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        foreach (['is_workshop', 'is_active'] as $column) {
            if (!Schema::hasColumn('factories', $column)) Schema::table('factories', fn (Blueprint $t) => $t->boolean($column)->default(true));
        }
        if (!Schema::hasTable('file_categories')) Schema::create('file_categories', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id')->index();
            $t->string('name'); $t->string('code', 80);
            // The old, non-null file FK is retained as a compatibility bridge; these rows are not workshops.
            $t->unsignedBigInteger('legacy_factory_id')->unique();
            $t->unsignedBigInteger('default_factory_id')->nullable();
            $t->boolean('is_reference')->default(false); $t->boolean('is_active')->default(true);
            $t->timestamps(); $t->unique(['company_id', 'code']);
        });
        if (!Schema::hasColumn('pmp_files', 'file_category_id')) Schema::table('pmp_files', fn (Blueprint $t) => $t->unsignedBigInteger('file_category_id')->nullable()->index());
        if (!DB::table('roles')->where('name', 'operator')->exists()) DB::table('roles')->insert(['name' => 'operator', 'value' => 'Օպերատոր']);
        foreach (DB::table('companies')->pluck('id') as $companyId) DB::transaction(function () use ($companyId) {
            DB::table('factories')->where('company_id', $companyId)->where(fn ($query) => $query->whereIn('value', ['SW', 'INFO', 'PDF'])->orWhere('is_reference', true))->update(['is_workshop' => false]);
            $powder = DB::table('factories')->where('company_id', $companyId)->where(function ($q) {
                $q->whereIn('value', ['POWDER', 'powder_catting'])->orWhereIn('name', ['Փոշեներկում', 'Powder coating', 'Powder', 'Порошковая покраска']);
            })->first();
            if (!$powder) DB::table('factories')->insert(['company_id' => $companyId, 'name' => 'Փոշեներկում', 'value' => 'POWDER', 'is_workshop' => true, 'is_active' => true, 'is_reference' => false, 'created_at' => now(), 'updated_at' => now()]);
            $names = ['SW' => 'CAD նախագծեր', 'DLD' => 'Կռման գծագրեր', 'DXF' => 'DXF գծագրեր', 'IQS' => '3D գծագրեր', 'INFO' => 'Տեղեկատու ֆայլեր', 'PDF' => 'PDF փաստաթղթեր'];
            foreach (DB::table('factories')->where('company_id', $companyId)->get() as $factory) {
                if (!isset($names[$factory->value]) && !DB::table('pmp_files')->where('factory_id', $factory->id)->exists()) continue;
                $category = DB::table('file_categories')->where('legacy_factory_id', $factory->id)->first();
                if (!$category) {
                    $id = DB::table('file_categories')->insertGetId(['company_id' => $companyId, 'legacy_factory_id' => $factory->id,
                        'name' => $names[$factory->value] ?? $factory->name, 'code' => $factory->value ?: 'FILES_'.$factory->id,
                        'default_factory_id' => $factory->is_workshop && !$factory->is_reference ? $factory->id : null,
                        'is_reference' => (bool) $factory->is_reference || in_array($factory->value, ['INFO', 'PDF'], true),
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                } else $id = $category->id;
                DB::table('pmp_files')->where('factory_id', $factory->id)->whereNull('file_category_id')->update(['file_category_id' => $id]);
            }
            $engineerId = DB::table('roles')->where('name', 'engineer')->value('id');
            DB::table('company_memberships')->where('company_id', $companyId)->where('role_id', $engineerId)->update(['factory_id' => null]);
            if (Schema::hasTable('membership_assignments')) {
                DB::table('membership_assignments')->whereIn('membership_id', DB::table('company_memberships')->where('company_id', $companyId)->select('id'))->where('role_id', $engineerId)->update(['factory_id' => null]);
                foreach (DB::table('company_memberships')->where('company_id', $companyId)->pluck('id') as $membershipId) {
                    $ids = DB::table('membership_assignments')->where('membership_id', $membershipId)->where('role_id', $engineerId)->orderBy('id')->pluck('id');
                    if ($ids->count() > 1) DB::table('membership_assignments')->whereIn('id', $ids->slice(1)->all())->delete();
                }
            }
        });
    }

    public function down(): void
    {
        // Keep categories and bridge IDs: removing them would destroy the meaning of existing files.
    }
};
