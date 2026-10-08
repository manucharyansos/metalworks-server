<?php

namespace Tests\Feature;

use App\Models\{Company, Factory, Permission, Role, User, Worker};
use Database\Seeders\RoleTableSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyMigrationTest extends TestCase
{
    public function test_existing_users_workshops_and_permissions_are_backfilled_without_changing_identity(): void
    {
        $this->artisan('migrate:install')->assertSuccessful();
        $latest = database_path('migrations/2026_10_07_150000_add_company_workspaces.php');
        $previous = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => $file !== $latest));
        app('migrator')->run($previous);
        $this->seed(RoleTableSeeder::class);
        $factory = Factory::create(['name' => 'Old workshop', 'value' => 'DXF']);
        $admin = User::create(['name' => 'Legacy Admin', 'email' => 'legacy-admin@example.invalid', 'password' => 'test-password', 'role_id' => Role::where('name', 'admin')->value('id')]);
        $worker = User::create(['name' => 'Legacy Worker', 'email' => 'legacy-worker@example.invalid', 'password' => 'test-password', 'role_id' => Role::where('name', 'laser')->value('id'), 'factory_id' => $factory->id]);
        Worker::create(['user_id' => $worker->id, 'last_name' => 'Surname', 'phone' => '091000000']);
        $passwordHash = $worker->password;
        $grant = Permission::where('slug', 'factory.download')->firstOrFail();
        DB::table('permission_user')->insert(['user_id' => $worker->id, 'permission_id' => $grant->id, 'allowed' => true]);
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
}
