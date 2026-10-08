<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) Schema::create('companies', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->unique();
            $table->string('logo_path')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        $metalworks = DB::table('companies')->where('slug', 'metalworks')->value('id');
        if (!$metalworks) $metalworks = DB::table('companies')->insertGetId(['name' => 'MetalWorks', 'slug' => 'metalworks', 'created_at' => now(), 'updated_at' => now()]);
        $legacyCutoff = DB::table('companies')->where('id', $metalworks)->value('created_at') ?? now();
        if (!Schema::hasColumn('users', 'is_platform_admin')) Schema::table('users', fn (Blueprint $t) => $t->boolean('is_platform_admin')->default(false));
        if (!Schema::hasTable('company_memberships')) Schema::create('company_memberships', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('user_id')->constrained();
            $t->foreignId('role_id')->nullable()->constrained(); $t->foreignId('factory_id')->nullable()->constrained();
            $t->boolean('is_active')->default(true); $t->timestamps(); $t->unique(['company_id', 'user_id']);
        });
        if (!Schema::hasTable('membership_permissions')) Schema::create('membership_permissions', function (Blueprint $t) {
            $t->id(); $t->foreignId('membership_id')->constrained('company_memberships')->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained(); $t->boolean('allowed')->default(true);
            $t->timestamps(); $t->unique(['membership_id', 'permission_id']);
        });
        foreach (config('companies.tables') as $table) {
            if (Schema::hasTable($table)) $this->ensureCompanyOwnership($table, $metalworks);
        }
        $adminRole = DB::table('roles')->where('name', 'admin')->value('id');
        // On a retry, accounts created after the enterprise migration started
        // must not gain MetalWorks access or installation-administrator rights.
        DB::table('users')->where(fn ($q) => $q->where('created_at', '<=', $legacyCutoff)->orWhereNull('created_at'))
            ->orderBy('id')->chunkById(500, function ($users) use ($metalworks, $adminRole) {
            foreach ($users as $user) {
                DB::table('company_memberships')->insertOrIgnore([
                    'company_id' => $metalworks, 'user_id' => $user->id, 'role_id' => $user->role_id,
                    'factory_id' => $user->factory_id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $membershipId = DB::table('company_memberships')->where('company_id', $metalworks)->where('user_id', $user->id)->value('id');
                if (Schema::hasTable('permission_user')) {
                    $rows = DB::table('permission_user')->where('user_id', $user->id)->get()->map(fn ($p) => [
                        'membership_id' => $membershipId, 'permission_id' => $p->permission_id,
                        'allowed' => $p->allowed ?? true, 'created_at' => now(), 'updated_at' => now(),
                    ])->all();
                    if ($rows) DB::table('membership_permissions')->insertOrIgnore($rows);
                }
                if ($adminRole && (int) $user->role_id === (int) $adminRole) DB::table('users')->where('id', $user->id)->update(['is_platform_admin' => true]);
            }
        });
        foreach (['workers' => 'user_id', 'pmps' => 'group', 'factory_order_statuses' => 'key', 'file_extensions' => 'extension', 'laser_file_extensions' => 'extension', 'bend_file_extensions' => 'extension'] as $table => $column) {
            if (Schema::hasTable($table)) $this->scopeUniqueIndex($table, $column);
        }
        if (Schema::hasTable('order_number_sequences')) {
            $this->ensureCompanyOwnership('order_number_sequences', $metalworks);
            $this->scopeUniqueIndex('order_number_sequences', 'period');
        }
    }

    private function ensureCompanyOwnership(string $table, int $companyId): void
    {
        // MySQL commits each DDL statement separately. A failed foreign-key
        // statement can therefore leave the column present: resume that state.
        if (!Schema::hasColumn($table, 'company_id')) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->default($companyId));
        }
        DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);
        if (!Schema::hasIndex($table, ['company_id'])) {
            Schema::table($table, fn (Blueprint $t) => $t->index('company_id', $table . '_company_id_index'));
        }
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if ($foreign['columns'] !== ['company_id']) continue;
            if ($foreign['foreign_table'] !== 'companies' || $foreign['foreign_columns'] !== ['id']) {
                throw new RuntimeException("Unexpected company foreign key on {$table}; ownership was not changed.");
            }
            return;
        }
        // index() belongs to a column or blueprint, not ForeignKeyDefinition:
        // chaining it after constrained() replaces the constraint name with 1.
        Schema::table($table, fn (Blueprint $t) => $t->foreign('company_id', $table . '_company_id_foreign')->references('id')->on('companies'));
    }

    private function scopeUniqueIndex(string $table, string $column): void
    {
        $legacy = $table . '_' . $column . '_unique';
        $scoped = $table . '_company_id_' . $column . '_unique';
        if ($table === 'workers' && !Schema::hasIndex($table, 'workers_user_id_index')) {
            // InnoDB needs an index starting with user_id for its existing FK;
            // the new (company_id, user_id) unique index cannot support it.
            Schema::table($table, fn (Blueprint $t) => $t->index('user_id', 'workers_user_id_index'));
        }
        if (!Schema::hasIndex($table, $scoped)) {
            Schema::table($table, fn (Blueprint $t) => $t->unique(['company_id', $column], $scoped));
        }
        if (Schema::hasIndex($table, $legacy)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique($legacy));
        }
    }

    public function down(): void
    {
        // An automatic rollback would merge independent companies and can lose
        // membership roles. Restore a database backup for a full rollback.
        throw new RuntimeException('Company migration cannot be rolled back automatically. Restore the pre-migration backup.');
    }
};
