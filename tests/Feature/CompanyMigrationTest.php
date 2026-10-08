<?php

namespace Tests\Feature;

use App\Models\{Company, Factory, Permission, Role, User, Worker};
use App\Support\CompanyContext;
use Database\Seeders\RoleTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyMigrationTest extends TestCase
{
    private function legacyInstallation(): array
    {
        $latest = database_path('migrations/2026_10_07_150000_add_company_workspaces.php');
        $previous = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < basename($latest)));
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        // A historical enum change predates these workspaces. Use Laravel's
        // native MySQL DDL rather than DBAL's unsupported enum conversion.
        $native = \Illuminate\Database\Schema\Builder::$alwaysUsesNativeSchemaOperationsIfPossible;
        if (DB::getDriverName() === 'mysql') Schema::useNativeSchemaOperationsIfPossible();
        try {
            $this->artisan('migrate:fresh', ['--path' => $previous, '--realpath' => true])->assertSuccessful();
        } finally {
            Schema::useNativeSchemaOperationsIfPossible($native);
        }
        $this->seed(RoleTableSeeder::class);
        $factory = Factory::create(['name' => 'Old workshop', 'value' => 'DXF']);
        $worker = User::create(['name' => 'Legacy Worker', 'email' => 'legacy-worker@example.invalid', 'password' => 'test-password', 'role_id' => Role::where('name', 'laser')->value('id'), 'factory_id' => $factory->id]);
        $profile = Worker::create(['user_id' => $worker->id, 'last_name' => 'Surname', 'phone' => '091000000']);
        $grant = Permission::where('slug', 'factory.download')->firstOrFail();
        DB::table('permission_user')->insert(['user_id' => $worker->id, 'permission_id' => $grant->id, 'allowed' => true]);
        return [$latest, $factory, $worker, $profile, $grant];
    }

    public function test_existing_users_workshops_and_permissions_are_backfilled_without_changing_identity(): void
    {
        [$latest, $factory, $worker, $profile, $grant] = $this->legacyInstallation();
        $admin = User::create(['name' => 'Legacy Admin', 'email' => 'legacy-admin@example.invalid', 'password' => 'test-password', 'role_id' => Role::where('name', 'admin')->value('id')]);
        $passwordHash = $worker->password;
        (require $latest)->up();
        $company = Company::where('slug', 'metalworks')->firstOrFail();
        $this->assertDatabaseHas('factories', ['id' => $factory->id, 'company_id' => $company->id, 'name' => 'Old workshop']);
        $this->assertDatabaseHas('workers', ['user_id' => $worker->id, 'company_id' => $company->id, 'last_name' => 'Surname']);
        $membershipId = DB::table('company_memberships')->where('user_id', $worker->id)->value('id');
        $this->assertDatabaseHas('company_memberships', ['id' => $membershipId, 'factory_id' => $factory->id, 'role_id' => $worker->role_id, 'is_active' => true]);
        $this->assertDatabaseHas('membership_permissions', ['membership_id' => $membershipId, 'permission_id' => $grant->id, 'allowed' => true]);
        $this->assertSame($passwordHash, $worker->fresh()->password);
        $this->assertTrue($admin->fresh()->is_platform_admin);
        $this->assertFalse($worker->fresh()->is_platform_admin);
    }

    public function test_retry_resumes_after_the_company_column_was_committed_without_its_foreign_key(): void
    {
        [$latest, $factory, $worker, $profile, $grant] = $this->legacyInstallation();
        $password = $worker->password;
        $interrupted = false;
        DB::listen(function ($query) use (&$interrupted) {
            if (!$interrupted && preg_match('/alter table [`"]?factories[`"]? add (?:column )?[`"]?company_id/i', $query->sql)) {
                $interrupted = true;
                throw new \RuntimeException('Interrupted after company column creation');
            }
        });
        try {
            (require $latest)->up();
            $this->fail('The migration should have been interrupted.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Interrupted after company column creation', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('factories', 'company_id'));
        $this->assertCount(1, DB::table('companies')->get());
        if (DB::getDriverName() === 'mysql') {
            // The historical orders migration already used this FK name.
            // Reproduce the exact production failure before resuming safely.
            $this->assertNotEmpty(collect(Schema::getForeignKeys('orders'))->where('name', '1'));
            try {
                Schema::table('factories', fn ($table) => $table->foreign('company_id', '1')->references('id')->on('companies'));
                $this->fail('The old company constraint name must collide.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('duplicate', strtolower($error->getMessage()));
            }
        }
        (require $latest)->up();
        $companyId = DB::table('companies')->where('slug', 'metalworks')->value('id');
        $this->assertCount(1, DB::table('companies')->get());
        $this->assertDatabaseHas('factories', ['id' => $factory->id, 'company_id' => $companyId, 'name' => 'Old workshop']);
        $this->assertDatabaseHas('workers', ['id' => $profile->id, 'company_id' => $companyId, 'user_id' => $worker->id]);
        $membershipId = DB::table('company_memberships')->where('user_id', $worker->id)->value('id');
        $this->assertDatabaseHas('membership_permissions', ['membership_id' => $membershipId, 'permission_id' => $grant->id, 'allowed' => true]);
        $this->assertSame($password, $worker->fresh()->password);
        $this->assertTrue(Schema::hasIndex('workers', 'workers_user_id_index'));
        $this->assertFalse(Schema::hasIndex('workers', 'workers_user_id_unique'));
        // Laravel 10's SQLite grammar ignores ALTER TABLE ADD FOREIGN KEY.
        // InnoDB coverage is required to verify the production constraints.
        if (DB::getDriverName() === 'mysql') {
            foreach (array_merge(config('companies.tables'), ['order_number_sequences']) as $table) {
                if (!Schema::hasTable($table)) continue;
                $foreign = collect(Schema::getForeignKeys($table))->first(fn ($key) => $key['columns'] === ['company_id']);
                $this->assertNotNull($foreign, "Missing company foreign key on {$table}");
                $this->assertSame('companies', $foreign['foreign_table']);
                $this->assertSame($table . '_company_id_foreign', $foreign['name']);
            }
        }
    }

    public function test_retry_preserves_existing_company_ownership_membership_changes_and_permissions(): void
    {
        [$latest, $factory, $worker, $profile, $grant] = $this->legacyInstallation();
        (require $latest)->up();
        $membershipId = DB::table('company_memberships')->where('user_id', $worker->id)->value('id');
        DB::table('company_memberships')->where('id', $membershipId)->update(['is_active' => false, 'role_id' => Role::where('name', 'engineer')->value('id'), 'factory_id' => null]);
        DB::table('membership_permissions')->where('membership_id', $membershipId)->where('permission_id', $grant->id)->update(['allowed' => false]);
        $second = Company::create(['name' => 'Other company', 'slug' => 'other']);
        $secondFactory = app(CompanyContext::class)->run($second, fn () => Factory::create(['name' => 'Other workshop', 'value' => 'DXF']));
        $newUser = User::create(['name' => 'New company administrator', 'email' => 'new-admin@example.invalid', 'password' => 'test-password', 'role_id' => Role::where('name', 'admin')->value('id')]);
        $newUser->forceFill(['created_at' => now()->addDay()])->save();
        (require $latest)->up();
        $this->assertDatabaseHas('factories', ['id' => $secondFactory->id, 'company_id' => $second->id]);
        $this->assertDatabaseHas('company_memberships', ['id' => $membershipId, 'is_active' => false, 'factory_id' => null]);
        $this->assertDatabaseHas('membership_permissions', ['membership_id' => $membershipId, 'permission_id' => $grant->id, 'allowed' => false]);
        $this->assertFalse($newUser->fresh()->is_platform_admin);
        $this->assertDatabaseMissing('company_memberships', ['user_id' => $newUser->id]);
        $this->assertSame(1, DB::table('company_memberships')->where('user_id', $worker->id)->count());
    }
}
