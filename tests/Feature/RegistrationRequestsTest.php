<?php

namespace Tests\Feature;

use App\Models\{Company, CompanyMembership, Factory, Permission, RegistrationRequest, Role, User};
use App\Support\CompanyContext;
use Database\Seeders\{FactorySeeder, RoleTableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, Hash, RateLimiter, Schema};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationRequestsTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private User $manager;
    private User $otherManager;
    private bool $nativeSchema = false;

    protected function beforeRefreshingDatabase(): void
    {
        $this->nativeSchema = \Illuminate\Database\Schema\Builder::$alwaysUsesNativeSchemaOperationsIfPossible;
        if (DB::getDriverName() === 'mysql') Schema::useNativeSchemaOperationsIfPossible();
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::useNativeSchemaOperationsIfPossible($this->nativeSchema);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleTableSeeder::class);
        $this->a = Company::where('slug', 'metalworks')->firstOrFail();
        $this->b = Company::create(['name' => 'Second Works', 'slug' => 'second']);
        foreach ([$this->a, $this->b] as $company) $this->in($company, fn () => (new FactorySeeder)->run());
        $this->manager = $this->account($this->a, 'manager@example.invalid', 'manager');
        $this->otherManager = $this->account($this->b, 'second-manager@example.invalid', 'manager');
        RateLimiter::clear('register|127.0.0.1');
    }

    public function test_public_directory_exposes_only_active_company_names(): void
    {
        Company::create(['name' => 'Closed Works', 'slug' => 'closed', 'is_active' => false]);
        $result = $this->getJson('/api/registration/companies', ['X-Company-ID' => 999])->assertOk()->assertJsonCount(2, 'companies');
        $this->assertSame(['id', 'name'], array_keys($result->json('companies.0')));
        $result->assertDontSee('Closed Works')->assertDontSee('DXF')->assertDontSee('manager@example.invalid');
        $this->getJson('/api/companies')->assertUnauthorized();
    }

    public function test_employee_submission_is_only_a_hashed_pending_request(): void
    {
        $this->postJson('/api/register', $this->payload(['is_employee' => true, 'last_name' => 'Surname', 'job_title' => 'admin', 'email' => ' New@Example.Invalid ']))
            ->assertStatus(202)->assertExactJson(['status' => 'pending', 'message' => 'Ձեր հարցումն ընդունված է։']);
        $row = RegistrationRequest::firstOrFail();
        $this->assertSame('employee', $row->type);
        $this->assertSame('admin', $row->job_title);
        $this->assertNull($row->patronymic);
        $this->assertTrue(Hash::check('new-password', $row->password_hash));
        $this->assertArrayNotHasKey('password_hash', $row->toArray());
        $this->assertArrayNotHasKey('existing_user_id', $row->toArray());
        $this->assertDatabaseMissing('users', ['email' => 'new@example.invalid']);
        $this->assertDatabaseCount('company_memberships', 2);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/login', ['email' => 'new@example.invalid', 'password' => 'new-password'])->assertUnauthorized();
    }

    public function test_client_needs_no_surname_patronymic_or_job_title(): void
    {
        $this->postJson('/api/register', $this->payload(['name' => 'Li']))->assertStatus(202);
        $this->assertDatabaseHas('registration_requests', ['name' => 'Li', 'type' => 'client', 'last_name' => null, 'patronymic' => null, 'job_title' => null, 'status' => 'pending']);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.invalid']);
    }

    public function test_employee_requires_surname_and_informational_job_title(): void
    {
        $this->postJson('/api/register', $this->payload(['is_employee' => true]))->assertUnprocessable()->assertJsonValidationErrors(['last_name', 'job_title']);
        $this->postJson('/api/register', $this->payload(['password_confirmation' => 'different']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('registration_requests', 0);
    }

    public function test_multiple_companies_require_explicit_selection_for_clients_and_employees(): void
    {
        $this->postJson('/api/register', $this->payload(['company_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->postJson('/api/register', $this->payload(['company_id' => null, 'is_employee' => true, 'last_name' => 'Surname', 'job_title' => 'Operator']))->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->b->update(['is_active' => false]);
        $this->postJson('/api/register', $this->payload(['company_id' => $this->b->id]))->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->assertDatabaseCount('registration_requests', 0);
    }

    public function test_only_active_company_is_assigned_automatically(): void
    {
        $this->b->update(['is_active' => false]);
        $this->postJson('/api/register', $this->payload(['company_id' => null]))->assertStatus(202);
        $this->assertDatabaseHas('registration_requests', ['company_id' => $this->a->id, 'email' => 'new@example.invalid']);
    }

    public function test_manager_chooses_real_employee_role_and_workshop_before_account_creation(): void
    {
        $this->postJson('/api/register', $this->payload(['is_employee' => true, 'last_name' => 'Surname', 'patronymic' => 'Middle', 'job_title' => 'admin']))->assertStatus(202);
        $request = RegistrationRequest::firstOrFail();
        $factory = $this->in($this->a, fn () => Factory::where('value', 'DXF')->firstOrFail());
        $this->actingManager($this->manager);
        $this->postJson($this->reviewUrl($request), ['role_id' => $this->role('laser'), 'factory_id' => $factory->id])->assertOk()->assertJsonPath('status', 'approved');
        $user = User::where('email', 'new@example.invalid')->firstOrFail();
        $this->assertDatabaseHas('company_memberships', ['user_id' => $user->id, 'company_id' => $this->a->id, 'role_id' => $this->role('laser'), 'factory_id' => $factory->id, 'is_active' => true]);
        $this->assertDatabaseHas('workers', ['user_id' => $user->id, 'company_id' => $this->a->id, 'phone' => '', 'last_name' => 'Surname']);
        $this->assertSame('Middle', $user->patronymic);
        $this->assertFalse($user->is_platform_admin);
        $this->assertNull($request->fresh()->password_hash);
        $this->assertSame($this->manager->id, $request->fresh()->reviewed_by);
        $this->assertDatabaseCount('membership_permissions', 0);
        $this->actingManager($user);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('role.name', 'laser')->assertJsonPath('factory_id', $factory->id)->assertJsonPath('companies.0.id', $this->a->id);
        $this->getJson('/api/registration-requests')->assertForbidden();
        $this->assertTrue(Hash::check('new-password', $user->password));
    }

    public function test_clients_are_approved_into_client_access_only(): void
    {
        $this->postJson('/api/register', $this->payload(['job_title' => 'admin', 'role_id' => $this->role('admin'), 'is_platform_admin' => true]))->assertStatus(202);
        $request = RegistrationRequest::firstOrFail();
        $this->assertNull($request->job_title);
        $this->actingManager($this->manager);
        $this->postJson($this->reviewUrl($request), ['role_id' => $this->role('admin')])->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->postJson($this->reviewUrl($request))->assertOk();
        $user = User::where('email', 'new@example.invalid')->firstOrFail();
        $this->assertDatabaseHas('clients', ['user_id' => $user->id, 'company_id' => $this->a->id, 'name' => 'New Person', 'phone' => '', 'type' => 'physPerson']);
        $this->assertDatabaseHas('company_memberships', ['user_id' => $user->id, 'role_id' => $this->role('authenticatedUser'), 'factory_id' => null]);
        $this->assertFalse($user->is_platform_admin);
        $this->assertDatabaseMissing('workers', ['user_id' => $user->id]);
        $this->actingManager($user);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('role.name', 'authenticatedUser');
        $this->getJson('/api/registration-requests')->assertForbidden();
    }

    public function test_role_and_workshop_errors_do_not_create_an_account(): void
    {
        $this->postJson('/api/register', $this->payload(['is_employee' => true, 'last_name' => 'Surname', 'job_title' => 'Operator']))->assertStatus(202);
        $request = RegistrationRequest::firstOrFail();
        $otherFactory = $this->in($this->b, fn () => Factory::firstOrFail());
        $this->actingManager($this->manager);
        $this->postJson($this->reviewUrl($request), ['role_id' => $this->role('admin')])->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->postJson($this->reviewUrl($request), ['role_id' => $this->role('authenticatedUser')])->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->postJson($this->reviewUrl($request), ['role_id' => $this->role('laser')])->assertUnprocessable()->assertJsonValidationErrors('factory_id');
        $this->postJson($this->reviewUrl($request), ['role_id' => $this->role('laser'), 'factory_id' => $otherFactory->id])->assertUnprocessable()->assertJsonValidationErrors('factory_id');
        $this->assertDatabaseMissing('users', ['email' => 'new@example.invalid']);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_lists_counts_options_and_review_bindings_are_private_to_the_selected_company(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(202);
        $this->postJson('/api/register', $this->payload(['email' => 'second@example.invalid', 'company_id' => $this->b->id]))->assertStatus(202);
        $otherRequest = RegistrationRequest::where('company_id', $this->b->id)->firstOrFail();
        $this->getJson('/api/registration-requests')->assertUnauthorized();
        $this->actingManager($this->manager);
        $list = $this->getJson('/api/registration-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('counts.pending', 1)->assertDontSee('second@example.invalid');
        $this->assertArrayNotHasKey('password_hash', $list->json('data.0'));
        $this->assertArrayNotHasKey('existing_user_id', $list->json('data.0'));
        $options = $this->getJson('/api/registration-requests/options')->assertOk()->assertJsonCount(6, 'factories');
        $this->assertNotContains('admin', array_column($options->json('roles'), 'name'));
        $this->postJson($this->reviewUrl($otherRequest))->assertNotFound();
        $this->postJson($this->reviewUrl($otherRequest, 'reject'))->assertNotFound();
        $this->getJson('/api/registration-requests', ['X-Company-ID' => $this->b->id])->assertForbidden();
        $this->actingManager($this->otherManager);
        $this->getJson('/api/registration-requests')->assertOk()->assertJsonPath('data.0.email', 'second@example.invalid')->assertDontSee('new@example.invalid');
    }

    public function test_platform_admin_can_review_selected_company_but_does_not_make_platform_admins(): void
    {
        $this->manager->forceFill(['is_platform_admin' => true])->save();
        $this->postJson('/api/register', $this->payload(['company_id' => $this->b->id, 'is_employee' => true, 'last_name' => 'Surname', 'job_title' => 'admin']))->assertStatus(202);
        $request = RegistrationRequest::firstOrFail();
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/registration-requests/options', ['X-Company-ID' => $this->b->id])->assertOk()->assertSee('admin');
        $this->postJson($this->reviewUrl($request), ['role_id' => $this->role('admin')], ['X-Company-ID' => $this->b->id])->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'new@example.invalid', 'is_platform_admin' => false]);
        $this->assertDatabaseHas('company_memberships', ['company_id' => $this->b->id, 'role_id' => $this->role('admin'), 'is_active' => true]);
    }

    public function test_rejection_creates_no_account_and_resubmission_returns_to_pending(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(202);
        $request = RegistrationRequest::firstOrFail();
        $this->actingManager($this->manager);
        $this->postJson($this->reviewUrl($request, 'reject'))->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertNull($request->fresh()->password_hash);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.invalid']);
        $this->publicAgain();
        $this->postJson('/api/register', $this->payload(['name' => 'Resubmitted']))->assertStatus(202);
        $this->assertDatabaseCount('registration_requests', 1);
        $this->assertDatabaseHas('registration_requests', ['id' => $request->id, 'status' => 'pending', 'reviewed_by' => null, 'name' => 'Resubmitted']);
    }

    public function test_repeat_review_conflicts_without_duplicate_accounts(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(202);
        $request = RegistrationRequest::firstOrFail();
        $this->actingManager($this->manager);
        $this->postJson($this->reviewUrl($request))->assertOk();
        $this->postJson($this->reviewUrl($request))->assertStatus(409);
        $this->postJson($this->reviewUrl($request, 'reject'))->assertStatus(409);
        $this->assertSame(1, User::where('email', 'new@example.invalid')->count());
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_pending_request_cannot_have_its_password_overwritten_by_another_visitor(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(202);
        $this->postJson('/api/register', $this->payload(['password' => 'hijack-password', 'password_confirmation' => 'hijack-password', 'name' => 'Attacker']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/register', $this->payload(['name' => 'Corrected']))->assertStatus(202);
        $this->assertDatabaseCount('registration_requests', 1);
        $this->assertSame('Corrected', RegistrationRequest::first()->name);
        $this->assertTrue(Hash::check('new-password', RegistrationRequest::first()->password_hash));
    }

    public function test_existing_email_requires_current_password_and_preserves_other_company_identity_and_grants(): void
    {
        $person = $this->account($this->b, 'new@example.invalid', 'engineer');
        $hash = $person->password;
        $otherMembership = CompanyMembership::where('user_id', $person->id)->firstOrFail();
        $permission = Permission::where('slug', 'pmp.view')->firstOrFail();
        $otherMembership->permissions()->attach($permission->id, ['allowed' => true]);
        $this->postJson('/api/register', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/register', $this->payload(['password' => 'existing-password', 'password_confirmation' => 'existing-password', 'name' => 'Company A Client']))->assertStatus(202);
        $request = RegistrationRequest::firstOrFail();
        $this->assertSame($person->id, $request->existing_user_id);
        $this->assertNull($request->password_hash);
        $this->actingManager($this->manager);
        $this->postJson($this->reviewUrl($request))->assertOk()->assertJsonPath('user_id', $person->id);
        $this->assertSame($hash, $person->fresh()->password);
        $this->assertSame('Existing Person', $person->fresh()->name);
        $this->assertSame($this->role('engineer'), $otherMembership->fresh()->role_id);
        $this->assertDatabaseHas('membership_permissions', ['membership_id' => $otherMembership->id, 'permission_id' => $permission->id, 'allowed' => true]);
        $this->assertDatabaseHas('clients', ['user_id' => $person->id, 'company_id' => $this->a->id, 'name' => 'Company A Client']);
    }

    public function test_two_new_requests_for_one_email_must_verify_identity_after_first_approval(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(202);
        $this->postJson('/api/register', $this->payload(['company_id' => $this->b->id, 'password' => 'other-password', 'password_confirmation' => 'other-password']))->assertStatus(202);
        $first = RegistrationRequest::where('company_id', $this->a->id)->firstOrFail();
        $second = RegistrationRequest::where('company_id', $this->b->id)->firstOrFail();
        $this->actingManager($this->manager);
        $this->postJson($this->reviewUrl($first))->assertOk();
        $this->actingManager($this->otherManager);
        $this->postJson($this->reviewUrl($second))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->publicAgain();
        $this->postJson('/api/register', $this->payload(['company_id' => $this->b->id, 'password' => 'other-password', 'password_confirmation' => 'other-password']))->assertUnprocessable();
        $this->postJson('/api/register', $this->payload(['company_id' => $this->b->id]))->assertStatus(202);
        $this->actingManager($this->otherManager);
        $this->postJson($this->reviewUrl($second))->assertOk();
        $this->assertSame(1, User::where('email', 'new@example.invalid')->count());
        $this->assertSame(2, CompanyMembership::where('user_id', $first->fresh()->user_id)->count());
    }

    public function test_active_members_cannot_change_their_role_through_public_registration(): void
    {
        $this->postJson('/api/register', $this->payload(['email' => $this->manager->email, 'password' => 'existing-password', 'password_confirmation' => 'existing-password']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('registration_requests', 0);
        $this->assertDatabaseHas('company_memberships', ['user_id' => $this->manager->id, 'role_id' => $this->role('manager'), 'is_active' => true]);
    }

    public function test_reactivation_clears_previous_job_grants_only_in_requested_company(): void
    {
        $person = $this->account($this->a, 'new@example.invalid', 'engineer');
        $membership = CompanyMembership::where('user_id', $person->id)->firstOrFail();
        $permission = Permission::where('slug', 'pmp.view')->firstOrFail();
        $membership->permissions()->attach($permission->id, ['allowed' => true]);
        $membership->update(['is_active' => false]);
        $this->postJson('/api/register', $this->payload(['password' => 'existing-password', 'password_confirmation' => 'existing-password']))->assertStatus(202);
        $this->actingManager($this->manager);
        $this->postJson($this->reviewUrl(RegistrationRequest::firstOrFail()))->assertOk();
        $this->assertDatabaseHas('company_memberships', ['id' => $membership->id, 'role_id' => $this->role('authenticatedUser'), 'is_active' => true]);
        $this->assertDatabaseMissing('membership_permissions', ['membership_id' => $membership->id]);
    }

    public function test_registration_limits_abuse_and_validation_does_not_create_accounts(): void
    {
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/register', [])->assertUnprocessable();
        $this->postJson('/api/register', $this->payload())->assertStatus(429)->assertJsonStructure(['retry_after']);
        $this->assertDatabaseCount('registration_requests', 0);
        $this->assertDatabaseCount('users', 2);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['name' => 'New Person', 'email' => 'new@example.invalid', 'password' => 'new-password', 'password_confirmation' => 'new-password', 'is_employee' => false, 'company_id' => $this->a->id], $extra);
    }

    private function reviewUrl(RegistrationRequest $request, string $action = 'approve'): string { return '/api/registration-requests/' . $request->id . '/' . $action; }
    private function role(string $name): int { return (int) Role::where('name', $name)->value('id'); }
    private function in(Company $company, callable $fn): mixed { return app(CompanyContext::class)->run($company, $fn); }
    private function account(Company $company, string $email, string $role): User { return $this->in($company, fn () => User::create(['name' => 'Existing Person', 'email' => $email, 'password' => 'existing-password', 'role_id' => $this->role($role)])); }
    private function actingManager(User $user): void { Sanctum::actingAs($user); $this->withHeader('X-Company-ID', $user->memberships()->where('is_active', true)->firstOrFail()->company_id); }
    private function publicAgain(): void { Auth::forgetGuards(); $this->flushHeaders(); }
}
