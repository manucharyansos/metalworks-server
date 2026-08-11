<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('permission_user', 'allowed')) {
            Schema::table('permission_user', function (Blueprint $table) {
                $table->boolean('allowed')->default(true)->after('permission_id');
            });
        }

        $permissions = [
            ['group' => 'pmp', 'slug' => 'pmp.view', 'name' => 'PMP դիտել'],
            ['group' => 'pmp', 'slug' => 'pmp.create', 'name' => 'PMP ստեղծել'],
            ['group' => 'pmp', 'slug' => 'pmp.update', 'name' => 'PMP թարմացնել'],

            ['group' => 'pmp_group', 'slug' => 'pmp_group.check_group', 'name' => 'PMP խումբ ստուգել'],
            ['group' => 'pmp_group', 'slug' => 'pmp_group.check_group_name', 'name' => 'PMP խմբի անուն ստուգել'],
            ['group' => 'pmp_group', 'slug' => 'pmp_group.check_remote_number', 'name' => 'Հեռակա համարով PMP ստուգել'],

            ['group' => 'pmp_files', 'slug' => 'pmp_files.view', 'name' => 'PMP ֆայլ դիտել'],
            ['group' => 'pmp_files', 'slug' => 'pmp_files.upload', 'name' => 'PMP ֆայլ վերբեռնել'],
            ['group' => 'pmp_files', 'slug' => 'pmp_files.delete', 'name' => 'PMP ֆայլ ջնջել'],

            ['group' => 'orders', 'slug' => 'orders.view', 'name' => 'Պատվեր դիտել'],
            ['group' => 'orders', 'slug' => 'orders.create', 'name' => 'Պատվեր ստեղծել'],
            ['group' => 'orders', 'slug' => 'orders.update', 'name' => 'Պատվերներ թարմացնել'],
            ['group' => 'orders', 'slug' => 'orders.delete', 'name' => 'Պատվերներ ջնջել'],

            ['group' => 'clients', 'slug' => 'clients.view', 'name' => 'Հաճախորդներ դիտել'],
            ['group' => 'clients', 'slug' => 'clients.create', 'name' => 'Հաճախորդ ստեղծել'],
            ['group' => 'clients', 'slug' => 'clients.update', 'name' => 'Հաճախորդ թարմացնել'],
            ['group' => 'clients', 'slug' => 'clients.delete', 'name' => 'Հաճախորդ ջնջել'],

            ['group' => 'workers', 'slug' => 'workers.view', 'name' => 'Աշխատակիցներ դիտել'],
            ['group' => 'workers', 'slug' => 'workers.create', 'name' => 'Աշխատակից ստեղծել'],
            ['group' => 'workers', 'slug' => 'workers.update', 'name' => 'Աշխատակից թարմացնել'],
            ['group' => 'workers', 'slug' => 'workers.delete', 'name' => 'Աշխատակից ջնջել'],

            ['group' => 'materials', 'slug' => 'materials.view', 'name' => 'Նյութեր դիտել'],
            ['group' => 'materials', 'slug' => 'materials.create', 'name' => 'Նյութ ավելացնել'],
            ['group' => 'materials', 'slug' => 'materials.update', 'name' => 'Նյութ թարմացնել'],
            ['group' => 'materials', 'slug' => 'materials.delete', 'name' => 'Նյութ ջնջել'],

            ['group' => 'material_categories', 'slug' => 'material_categories.view', 'name' => 'Նյութերի կատեգորիաներ դիտել'],
            ['group' => 'roles', 'slug' => 'roles.view', 'name' => 'Ռոլեր դիտել'],

            ['group' => 'factory', 'slug' => 'factory.view', 'name' => 'Գործարան դիտել'],
            ['group' => 'factory', 'slug' => 'factory.order_update', 'name' => 'Պատվեր թարմացնել (գործարան)'],
            ['group' => 'factory', 'slug' => 'factory.download', 'name' => 'Գործարանի ֆայլ ներբեռնել'],
        ];

        $now = now();

        foreach ($permissions as $permission) {
            $query = DB::table('permissions')->where('slug', $permission['slug']);

            if ($query->exists()) {
                $query->update([
                    'name' => $permission['name'],
                    'group' => $permission['group'],
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('permissions')->insert([
                'name' => $permission['name'],
                'slug' => $permission['slug'],
                'group' => $permission['group'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('permission_user', 'allowed')) {
            Schema::table('permission_user', function (Blueprint $table) {
                $table->dropColumn('allowed');
            });
        }
    }
};
