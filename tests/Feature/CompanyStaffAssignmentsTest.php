<?php

namespace Tests\Feature;

use App\Models\{Company, CompanyMembership, Factory, File, Order, Permission, RegistrationRequest, Role, User};
use App\Support\{CompanyContext, MembershipAssignments};
use Database\Seeders\{FactorySeeder, RoleTableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, RateLimiter, Schema, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyStaffAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private User $manager;
    private User $admin;
    private array $factories;
    private bool $nativeSchema = false;

    protected function beforeRefreshingDatabase(): void
    {
        $this->nativeSchema = \Illuminate\Database\Schema\Builder::$alwaysUsesNativeSchemaOperationsIfPossible;
        if (DB::getDriverName() === 'mysql') Schema::useNativeSchemaOperationsIfPossible();
    }
    protected function afterRefreshingDatabase(): void { Schema::useNativeSchemaOperationsIfPossible($this->nativeSchema); }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleTableSeeder::class);
        $this->a = Company::where('slug', 'metalworks')->firstOrFail();
        $this->b = Company::create(['name' => 'Second Works', 'slug' => 'second']);
        foreach ([$this->a, $this->b] as $company) $this->in($company, fn () => (new FactorySeeder)->run());
        $this->factories = $this->in($this->a, fn () => Factory::orderBy('id')->limit(2)->get()->all());
        $this->manager = $this->account($this->a, 'manager@example.invalid', 'manager');
        $this->admin = $this->account($this->a, 'admin@example.invalid', 'admin');
        $this->admin->forceFill(['is_platform_admin' => true])->save();
        RateLimiter::clear('register|127.0.0.1');
        Storage::fake('private');
    }

    public function test_manager_approves_several_positions_and_workshops_without_automatic_grants(): void
    {
        $this->submitEmployee();
        $request = RegistrationRequest::firstOrFail();
        $this->as($this->manager);
        $this->postJson('/api/registration-requests/'.$request->id.'/approve', ['assignments' => $this->positions()], $this->headers())->assertOk();
        $user = User::where('email', 'new@example.invalid')->firstOrFail();
        $membership = $this->membership($user);
        $this->assertSame(3, $membership->assignments()->count());
        $this->assertSame($this->role('engineer'), (int) $membership->role_id);
        $this->assertNull($membership->factory_id);
        $this->assertDatabaseMissing('membership_permissions', ['membership_id' => $membership->id]);
        $this->assertDatabaseHas('workers', ['user_id' => $user->id, 'company_id' => $this->a->id, 'phone' => '']);
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->as($user);
        $result = $this->getJson('/api/user', $this->headers())->assertOk()->assertJsonCount(3, 'assignments')->assertJsonPath('role.name', 'engineer');
        $this->assertSame($result->json('assignments.0.id'), $result->json('assignment_id'));
        $this->getJson('/api/factories/factory', $this->headers($result->json('assignments.1.id')))->assertForbidden();
    }

    public function test_admin_can_approve_a_manager_plus_operator_and_manager_can_edit_later(): void
    {
        $this->submitEmployee();
        $this->as($this->admin);
        $this->postJson('/api/registration-requests/'.RegistrationRequest::first()->id.'/approve', ['assignments' => [$this->position('manager'), $this->position('bend', $this->factories[1]->id)]], $this->headers())->assertOk();
        $user = User::where('email', 'new@example.invalid')->firstOrFail();
        $hash = $user->password;
        $this->as($this->manager);
        $this->getJson('/api/staff-assignments/'.$user->id, $this->headers())->assertOk()->assertJsonPath('read_only', false)->assertJsonCount(2, 'assignments');
        $this->putJson('/api/staff-assignments/'.$user->id, ['assignments' => $this->positions()], $this->headers())->assertOk()->assertJsonCount(3, 'assignments');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseHas('workers', ['user_id' => $user->id, 'phone' => '']);
        $this->as($this->admin);
        $this->putJson('/api/staff-assignments/'.$user->id, ['assignments' => [$this->position('bend', $this->factories[1]->id)]], $this->headers())->assertOk()->assertJsonCount(1, 'assignments');
    }

    public function test_invalid_assignment_lists_do_not_create_accounts_or_approve_requests(): void
    {
        $this->submitEmployee();
        $this->as($this->manager);
        $foreign = $this->in($this->b, fn () => Factory::firstOrFail());
        foreach ([
            [$this->position('engineer'), $this->position('engineer')],
            [$this->position('laser')], [$this->position('laser', $foreign->id)],
            [$this->position('admin')], [$this->position('engineer', $this->factories[0]->id)], [],
        ] as $positions) {
            $this->postJson('/api/registration-requests/'.RegistrationRequest::first()->id.'/approve', ['assignments' => $positions], $this->headers())->assertUnprocessable();
        }
        $this->assertDatabaseMissing('users', ['email' => 'new@example.invalid']);
        $this->assertDatabaseHas('registration_requests', ['email' => 'new@example.invalid', 'status' => 'pending']);
    }

    public function test_union_grants_are_filtered_by_the_active_position_and_expand_dependencies(): void
    {
        $person = $this->employee();
        $this->as($this->manager);
        $options = $this->getJson('/api/users/'.$person->id.'/permissions', $this->headers())->assertOk()->assertJsonPath('permission_scope.roles', ['engineer', 'laser']);
        $this->assertContains('factory.download', array_column($options->json('permissions'), 'slug'));
        $this->assertContains('pmp.view', array_column($options->json('permissions'), 'slug'));
        $this->assertNotContains('workers.update', array_column($options->json('permissions'), 'slug'));
        $ids = Permission::whereIn('slug', ['pmp.view', 'factory.download'])->pluck('id')->all();
        $this->putJson('/api/users/'.$person->id.'/permissions', ['permissions' => $ids], $this->headers())->assertOk();
        $assignments = $this->membership($person)->assignments()->orderBy('id')->get();
        $engineer = $assignments->firstWhere('role_id', $this->role('engineer'));
        $laser = $assignments->firstWhere('role_id', $this->role('laser'));
        $this->as($person);
        $one = $this->getJson('/api/user', $this->headers($engineer->id))->assertOk()->assertJsonPath('role.name', 'engineer');
        $this->assertContains('pmp.view', $one->json('permissions')); $this->assertNotContains('factory.download', $one->json('permissions'));
        $two = $this->getJson('/api/user', $this->headers($laser->id))->assertOk()->assertJsonPath('role.name', 'laser');
        $this->assertContains('factory.download', $two->json('permissions')); $this->assertContains('factory.view', $two->json('permissions')); $this->assertNotContains('pmp.view', $two->json('permissions'));
        $this->getJson('/api/staff-assignments/'.$person->id, $this->headers($laser->id))->assertForbidden();
    }

    public function test_selecting_workshops_restricts_orders_status_changes_and_embedded_file_links(): void
    {
        $person = $this->employee(); $this->grant($person, ['factory.view', 'factory.download', 'factory.order_update']);
        $rows = $this->membership($person)->assignments()->where('role_id', $this->role('laser'))->orderBy('id')->get();
        $customer = $this->account($this->a, 'customer@example.invalid', 'authenticatedUser');
        $orders = [];
        foreach ($this->factories as $index => $factory) $orders[] = $this->in($this->a, function () use ($person, $customer, $factory, $index) {
            $order = Order::create(['name' => 'Private '.$index, 'user_id' => $customer->id, 'creator_id' => $this->manager->id]);
            $order->factories()->attach($factory->id, ['operator_id' => $person->id]);
            $file = File::create(['order_id' => $order->id, 'path' => 'orders/private-'.$index.'.txt', 'original_name' => 'private.txt']);
            Storage::disk('private')->put($file->path, 'Private '.$index);
            return [$order, $file];
        });
        $this->as($person);
        foreach ($rows as $index => $assignment) {
            $this->getJson('/api/factories/factory', $this->headers($assignment->id))->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $assignment->factory_id);
            $this->getJson('/api/factories/factory/'.$assignment->factory_id, $this->headers($assignment->id))->assertOk()->assertSee('Private '.$index)->assertDontSee('Private '.(1-$index));
            $this->getJson('/api/factories/factory/'.$this->factories[1-$index]->id, $this->headers($assignment->id))->assertForbidden();
            $this->get('/api/secure-files/order/'.$orders[$index][1]->id.'?company_id='.$this->a->id.'&assignment_id='.$assignment->id.'&download=1', ['Accept' => 'application/json'])->assertOk()->assertHeader('X-Assignment-ID', (string) $assignment->id);
            $this->getJson('/api/secure-files/order/'.$orders[1-$index][1]->id, $this->headers($assignment->id))->assertForbidden();
            $this->putJson('/api/factories/updateOrder/'.$orders[1-$index][0]->id, ['factory_id' => $this->factories[1-$index]->id, 'factory_order' => ['status' => 'pending']], $this->headers($assignment->id))->assertForbidden();
        }
    }

    public function test_removed_assignment_is_denied_and_common_assignments_keep_their_ids(): void
    {
        $person = $this->employee(); $this->grant($person, ['pmp.view', 'factory.download']);
        $before = $this->membership($person)->assignments()->orderBy('id')->get();
        $kept = $before->firstWhere('role_id', $this->role('engineer'));
        $removed = $before->firstWhere('factory_id', $this->factories[0]->id);
        $this->as($this->manager);
        $this->putJson('/api/staff-assignments/'.$person->id, ['assignments' => [$this->position('engineer')]], $this->headers())->assertOk()->assertJsonPath('assignments.0.id', $kept->id);
        $this->assertDatabaseHas('membership_permissions', ['membership_id' => $this->membership($person)->id, 'permission_id' => Permission::where('slug', 'pmp.view')->value('id')]);
        $this->assertDatabaseMissing('membership_permissions', ['membership_id' => $this->membership($person)->id, 'permission_id' => Permission::where('slug', 'factory.download')->value('id')]);
        $this->as($person);
        $this->getJson('/api/user', $this->headers($removed->id))->assertForbidden();
        $this->putJson('/api/factories/updateOrder/1', ['factory_id' => $removed->factory_id], $this->headers($removed->id))->assertForbidden();
        $this->getJson('/api/user', $this->headers())->assertOk()->assertJsonPath('assignment_id', $kept->id)->assertJsonPath('role.name', 'engineer');
    }

    public function test_cannot_select_another_users_position_or_a_position_from_another_company(): void
    {
        $person = $this->employee();
        $other = $this->account($this->b, 'other@example.invalid', 'manager');
        $foreign = $this->membership($other, $this->b)->assignments()->first();
        $managerAssignment = $this->membership($this->manager)->assignments()->first();
        $this->as($person);
        foreach ([$foreign->id, $managerAssignment->id, 'invalid', '0'] as $id) $this->getJson('/api/user', $this->headers($id))->assertForbidden();
        $own = $this->membership($person)->assignments()->first();
        $this->getJson('/api/user', ['X-Company-ID' => $this->b->id, 'X-Assignment-ID' => $own->id])->assertForbidden();
        $this->membership($person)->update(['is_active' => false]);
        $this->getJson('/api/user', $this->headers($own->id))->assertForbidden();
    }

    public function test_admin_position_is_protected_even_if_it_is_not_the_primary_position(): void
    {
        $person = $this->employee();
        $this->in($this->a, fn () => MembershipAssignments::sync($this->membership($person), [$this->position('engineer'), $this->position('admin')]));
        $this->as($this->manager);
        $this->getJson('/api/staff-assignments/'.$person->id, $this->headers())->assertOk()->assertJsonPath('read_only', true);
        $this->putJson('/api/staff-assignments/'.$person->id, ['assignments' => [$this->position('engineer')]], $this->headers())->assertForbidden();
        $this->deleteJson('/api/workers/'.$person->id, [], $this->headers())->assertForbidden();
        $this->putJson('/api/workers/'.$person->id, ['name' => $person->name, 'email' => $person->email, 'phone' => '123', 'assignments' => [$this->position('engineer')]], $this->headers())->assertForbidden();
        $this->as($this->admin);
        $this->putJson('/api/staff-assignments/'.$person->id, ['assignments' => [$this->position('engineer')]], $this->headers())->assertOk();
    }

    public function test_both_staff_forms_save_multiple_assignments_and_preserve_unchanged_legacy_company_rows(): void
    {
        $this->as($this->admin);
        $payload = ['name' => 'Worker', 'email' => 'worker@example.invalid', 'phone' => '123', 'password' => 'test-password', 'password_confirmation' => 'test-password', 'assignments' => $this->positions()];
        $response = $this->postJson('/api/workers', $payload, $this->headers())->assertCreated()->assertJsonCount(3, 'assignments');
        $person = User::findOrFail($response->json('id'));
        unset($payload['password'], $payload['password_confirmation']);
        $payload['assignments'] = [$this->position('laser', $this->factories[0]->id), $this->position('engineer'), $this->position('laser', $this->factories[1]->id)];
        $this->putJson('/api/workers/'.$person->id, $payload, $this->headers())->assertOk()->assertJsonCount(3, 'data.assignments')->assertJsonPath('data.role', 'laser');
        $this->as($this->manager);
        $payload['assignments'] = $this->positions();
        $this->putJson('/api/workers/'.$person->id, $payload, $this->headers())->assertOk()->assertJsonCount(3, 'data.assignments');
        $this->as($this->admin);
        $payload['company_access'] = [['company_id' => $this->a->id, 'enabled' => true, ...$this->position('engineer')]];
        $this->putJson('/api/workers/'.$person->id, $payload, $this->headers())->assertOk()->assertJsonCount(3, 'data.assignments');
    }

    public function test_cross_company_editor_assigns_multiple_roles_without_copying_source_grants(): void
    {
        $person = $this->employee(); $this->grant($person, ['pmp.view']);
        CompanyMembership::create(['company_id' => $this->b->id, 'user_id' => $this->manager->id, 'role_id' => $this->role('manager')]);
        $foreign = $this->in($this->b, fn () => Factory::firstOrFail());
        $this->as($this->manager);
        $this->putJson('/api/company-access/'.$person->id, ['access' => [['company_id' => $this->b->id, 'enabled' => true, 'assignments' => [$this->position('engineer'), $this->position('bend', $foreign->id)]]]], $this->headers())->assertOk();
        $membership = $this->membership($person, $this->b);
        $this->assertSame(2, $membership->assignments()->count());
        $this->assertDatabaseMissing('membership_permissions', ['membership_id' => $membership->id]);
        $this->assertSame(3, $this->membership($person)->assignments()->count());
        $this->getJson('/api/staff-assignments/'.$person->id, $this->headers())->assertOk()->assertJsonCount(3, 'assignments');
        $this->putJson('/api/company-access/'.$person->id, ['access' => [['company_id' => $this->b->id, 'enabled' => true, 'assignments' => [$this->position('bend', $this->factories[0]->id)]]]], $this->headers())->assertUnprocessable();
        $this->assertSame(2, $membership->assignments()->count());
    }

    public function test_operator_directory_and_dashboard_include_secondary_positions_once(): void
    {
        $person = $this->employee();
        $this->in($this->a, fn () => MembershipAssignments::sync($this->membership($person), [$this->position('engineer'), $this->position('laser', $this->factories[0]->id), $this->position('bend', $this->factories[0]->id)]));
        $this->as($this->manager);
        $result = $this->getJson('/api/factories/factory', $this->headers())->assertOk();
        $factory = collect($result->json())->firstWhere('id', $this->factories[0]->id);
        $this->assertSame([$person->id], array_column($factory['operators'], 'id'));
        $dashboard = $this->getJson('/api/admin/dashboard', $this->headers())->assertOk();
        $row = collect($dashboard->json('factories'))->firstWhere('id', $this->factories[0]->id);
        $this->assertSame(1, $row['operators_count']);
        $this->assertContains($person->id, array_column($dashboard->json('operators'), 'id'));
        $this->membership($person)->update(['is_active' => false]);
        $dashboard = $this->getJson('/api/admin/dashboard', $this->headers())->assertOk();
        $row = collect($dashboard->json('factories'))->firstWhere('id', $this->factories[0]->id);
        $this->assertSame(0, $row['operators_count']);
    }

    public function test_backfill_resumes_without_collapsing_multiple_assignments_or_changing_permissions(): void
    {
        $person = $this->employee(); $this->grant($person, ['pmp.view']);
        $before = $this->membership($person)->assignments()->orderBy('id')->get()->toArray();
        $managerMembership = $this->membership($this->manager);
        $managerMembership->assignments()->delete();
        $migration = require database_path('migrations/2026_10_09_030000_add_membership_assignments.php');
        $migration->up(); $migration->up();
        $this->assertSame($before, $this->membership($person)->assignments()->orderBy('id')->get()->toArray());
        $this->assertSame(1, $managerMembership->assignments()->count());
        $this->assertDatabaseHas('membership_permissions', ['membership_id' => $this->membership($person)->id, 'permission_id' => Permission::where('slug', 'pmp.view')->value('id')]);
    }

    private function in(Company $company, callable $fn): mixed { return app(CompanyContext::class)->run($company, $fn); }
    private function role(string $name): int { return (int) Role::where('name', $name)->value('id'); }
    private function position(string $name, ?int $factoryId = null): array { return ['role_id' => $this->role($name), 'factory_id' => $factoryId]; }
    private function positions(): array { return [$this->position('engineer'), $this->position('laser', $this->factories[0]->id), $this->position('laser', $this->factories[1]->id)]; }
    private function account(Company $company, string $email, string $role): User { return $this->in($company, fn () => User::create(['name' => 'Test Person', 'email' => $email, 'password' => 'test-password', 'role_id' => $this->role($role)])); }
    private function membership(User $user, ?Company $company = null): CompanyMembership { return CompanyMembership::where('user_id', $user->id)->where('company_id', ($company ?? $this->a)->id)->firstOrFail(); }
    private function headers(mixed $assignmentId = null): array { return ['X-Company-ID' => $this->a->id, ...($assignmentId !== null ? ['X-Assignment-ID' => (string) $assignmentId] : [])]; }
    private function as(User $user): void { app(CompanyContext::class)->set(null); $user->unsetRelation('role')->unsetRelation('factory'); Sanctum::actingAs($user); }
    private function employee(): User { $person = $this->account($this->a, 'employee@example.invalid', 'engineer'); $this->in($this->a, fn () => MembershipAssignments::sync($this->membership($person), $this->positions())); return $person; }
    private function grant(User $user, array $slugs): void { $this->membership($user)->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all()); }
    private function submitEmployee(): void { $this->postJson('/api/register', ['name' => 'New', 'last_name' => 'Employee', 'email' => 'new@example.invalid', 'password' => 'new-password', 'password_confirmation' => 'new-password', 'job_title' => 'Engineer/operator', 'is_employee' => true, 'company_id' => $this->a->id])->assertStatus(202); }
}
