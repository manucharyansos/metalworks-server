<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $backupTable = 'permission_user_reset_backup_20260811';

    public function up(): void
    {
        if (!Schema::hasTable('permission_user')) {
            return;
        }

        if (!Schema::hasTable($this->backupTable)) {
            Schema::create($this->backupTable, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('permission_id');
                $table->boolean('allowed')->default(true);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['user_id', 'permission_id'], 'permission_reset_backup_unique');
            });
        }

        DB::table($this->backupTable)->delete();

        DB::table('permission_user')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                $backupRows = [];

                foreach ($rows as $row) {
                    $backupRows[] = [
                        'user_id' => $row->user_id,
                        'permission_id' => $row->permission_id,
                        'allowed' => property_exists($row, 'allowed') ? (bool) $row->allowed : true,
                        'created_at' => $row->created_at ?? null,
                        'updated_at' => $row->updated_at ?? null,
                    ];
                }

                if ($backupRows !== []) {
                    DB::table($this->backupTable)->insertOrIgnore($backupRows);
                }
            });

        // New permission model starts with no individual grants. Admin explicitly
        // enables only the functions each staff member needs.
        DB::table('permission_user')->delete();
    }

    public function down(): void
    {
        if (!Schema::hasTable('permission_user') || !Schema::hasTable($this->backupTable)) {
            return;
        }

        DB::table('permission_user')->delete();

        DB::table($this->backupTable)
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                $restoreRows = [];

                foreach ($rows as $row) {
                    $restoreRows[] = [
                        'user_id' => $row->user_id,
                        'permission_id' => $row->permission_id,
                        'allowed' => (bool) $row->allowed,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                }

                if ($restoreRows !== []) {
                    DB::table('permission_user')->insertOrIgnore($restoreRows);
                }
            });

        Schema::dropIfExists($this->backupTable);
    }
};
