<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $labels = [
            'pmp.view' => 'Դիտել PMP բաժինը',
            'pmp.create' => 'Ստեղծել PMP',
            'pmp.update' => 'Խմբագրել PMP',
            'pmp_group.check_group' => 'Ստուգել PMP ծածկագրի գոյությունը',
            'pmp_group.check_group_name' => 'Ստուգել PMP անվան գոյությունը',
            'pmp_group.check_remote_number' => 'Ստուգել PMP ենթահամարը',
            'pmp_files.view' => 'Դիտել PMP ֆայլերը',
            'pmp_files.upload' => 'Վերբեռնել PMP ֆայլ',
            'pmp_files.delete' => 'Ջնջել PMP ֆայլ',
            'orders.view' => 'Դիտել պատվերները',
            'orders.create' => 'Ստեղծել պատվեր',
            'orders.update' => 'Խմբագրել պատվեր',
            'orders.delete' => 'Ջնջել պատվեր',
            'clients.view' => 'Դիտել հաճախորդների ցանկը',
            'clients.create' => 'Ստեղծել հաճախորդ',
            'clients.update' => 'Խմբագրել հաճախորդ',
            'clients.delete' => 'Ջնջել հաճախորդ',
            'workers.view' => 'Դիտել աշխատակիցների ցանկը',
            'workers.create' => 'Ստեղծել աշխատակից',
            'workers.update' => 'Խմբագրել աշխատակից',
            'workers.delete' => 'Ջնջել աշխատակից',
            'materials.view' => 'Դիտել նյութերի ցանկը',
            'materials.create' => 'Ավելացնել նյութ',
            'materials.update' => 'Խմբագրել նյութ',
            'materials.delete' => 'Ջնջել նյութ',
            'material_categories.view' => 'Դիտել նյութերի կատեգորիաները',
            'roles.view' => 'Դիտել հաստիքները',
            'factory.view' => 'Դիտել արտադրամասերի տվյալները',
            'factory.order_update' => 'Փոխել արտադրամասի աշխատանքի կարգավիճակը',
            'factory.download' => 'Դիտել և ներբեռնել արտադրամասի ֆայլերը',
        ];

        foreach ($labels as $slug => $name) {
            DB::table('permissions')
                ->where('slug', $slug)
                ->update([
                    'name' => $name,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Labels are presentation data; keeping the normalized wording is safe.
    }
};
