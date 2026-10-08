<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->unique();
            $table->string('logo_path')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        $metalworks = DB::table('companies')->insertGetId(['name' => 'MetalWorks', 'slug' => 'metalworks', 'created_at' => now(), 'updated_at' => now()]);
        Schema::table('users', fn (Blueprint $t) => $t->boolean('is_platform_admin')->default(false));
        Schema::create('company_memberships', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('user_id')->constrained();
            $t->foreignId('role_id')->nullable()->constrained(); $t->foreignId('factory_id')->nullable()->constrained();
            $t->boolean('is_active')->default(true); $t->timestamps(); $t->unique(['company_id', 'user_id']);
        });
        Schema::create('membership_permissions', function (Blueprint $t) {
            $t->id(); $t->foreignId('membership_id')->constrained('company_memberships')->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained(); $t->boolean('allowed')->default(true);
            $t->timestamps(); $t->unique(['membership_id', 'permission_id']);
        });
        foreach (config('companies.tables') as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, fn (Blueprint $t) => $t->foreignId('company_id')->default($metalworks)->constrained('companies')->index());
                DB::table($table)->update(['company_id' => $metalworks]);
            }
        }
        DB::table('users')->orderBy('id')->chunkById(500, function ($users) use ($metalworks) {
            foreach ($users as $user) {
                $membershipId = DB::table('company_memberships')->insertGetId([
                    'company_id' => $metalworks, 'user_id' => $user->id, 'role_id' => $user->role_id,
                    'factory_id' => $user->factory_id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if (Schema::hasTable('permission_user')) {
                    $rows = DB::table('permission_user')->where('user_id', $user->id)->get()->map(fn ($p) => [
                        'membership_id' => $membershipId, 'permission_id' => $p->permission_id,
                        'allowed' => $p->allowed ?? true, 'created_at' => now(), 'updated_at' => now(),
                    ])->all();
                    if ($rows) DB::table('membership_permissions')->insert($rows);
                }
            }
        });
        // Legacy admins already managed the whole installation. New company
        // admins do not receive this flag; its management is console-only.
        DB::table('users')->whereIn('role_id', DB::table('roles')->where('name', 'admin')->select('id'))->update(['is_platform_admin' => true]);
        if (Schema::hasTable('workers')) {
            Schema::table('workers', function (Blueprint $t) { $t->dropUnique('workers_user_id_unique'); $t->unique(['company_id', 'user_id']); });
        }
        foreach (['pmps' => 'group', 'factory_order_statuses' => 'key', 'file_extensions' => 'extension', 'laser_file_extensions' => 'extension', 'bend_file_extensions' => 'extension'] as $table => $column) {
            if (Schema::hasTable($table)) Schema::table($table, function (Blueprint $t) use ($table, $column) {
                $t->dropUnique($table . '_' . $column . '_unique'); $t->unique(['company_id', $column]);
            });
        }
        Schema::table('order_number_sequences', function (Blueprint $t) use ($metalworks) {
            $t->foreignId('company_id')->default($metalworks)->constrained('companies');
            $t->dropUnique('order_number_sequences_period_unique'); $t->unique(['company_id', 'period']);
        });
    }

    public function down(): void
    {
        // An automatic rollback would merge independent companies and can lose
        // membership roles. Restore a database backup for a full rollback.
        throw new RuntimeException('Company migration cannot be rolled back automatically. Restore the pre-migration backup.');
    }
};
