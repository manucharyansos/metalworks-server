<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['deadline' => fn (Blueprint $t) => $t->date('deadline')->nullable(),
                  'priority' => fn (Blueprint $t) => $t->string('priority', 10)->default('normal')] as $column => $add) {
            if (!Schema::hasColumn('factory_orders', $column)) Schema::table('factory_orders', $add);
        }
        if (!Schema::hasTable('task_file_revisions')) Schema::create('task_file_revisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('order_id')->index();
            $t->unsignedBigInteger('pmp_file_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedInteger('version');
            $t->string('file_path');
            $t->string('original_name');
            $t->unsignedBigInteger('file_size');
            $t->string('mime_type')->nullable();
            $t->string('note', 1000)->nullable();
            $t->timestamps();
            $t->unique(['order_id', 'pmp_file_id', 'version'], 'task_file_revision_version_unique');
        });
        foreach (['factory_order_files', 'selected_files'] as $table) {
            if (!Schema::hasColumn($table, 'task_file_revision_id')) Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('task_file_revision_id')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['factory_order_files', 'selected_files'] as $table) {
            if (Schema::hasColumn($table, 'task_file_revision_id')) Schema::table($table, fn (Blueprint $t) => $t->dropColumn('task_file_revision_id'));
        }
        Schema::dropIfExists('task_file_revisions');
        foreach (['deadline', 'priority'] as $column) {
            if (Schema::hasColumn('factory_orders', $column)) Schema::table('factory_orders', fn (Blueprint $t) => $t->dropColumn($column));
        }
    }
};
