<?php

namespace Tests\Feature;

use App\Mail\RegistrationApproved;
use App\Models\{Company, CompanyMembership, Factory, Permission, RegistrationRequest, Role, User};
use App\Support\CompanyContext;
use Database\Seeders\{FactorySeeder, RoleTableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Mail, RateLimiter, Schema, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyRegistrationFollowupTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private Company $c;
    private User $manager;
    private bool $nativeSchema;

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
        $this->c = Company::create(['name' => 'Private Third', 'slug' => 'third']);
        foreach ([$this->a, $this->b, $this->c] as $company) app(CompanyContext::class)->run($company, fn () => (new FactorySeeder)->run());
        $this->manager = $this->account($this->a, 'manager-followup@example.invalid', 'manager');
        RateLimiter::clear('register|127.0.0.1');
    }

    public function test_active_brand_logos_are_public_but_business_directories_remain_private(): void
    {
        Storage::fake('private');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jvB0AAAAASUVORK5CYII=');
        Storage::disk('private')->put('companies/2/branding/logo.png', $png);
        $this->b->update(['logo_path' => 'companies/2/branding/logo.png']);
        $this->c->update(['is_active' => false]);
        $response = $this->getJson('/api/workspace/brands', ['X-Company-ID' => 999])->assertOk()->assertJsonCount(2, 'brands');
        $this->assertSame(['id', 'name', 'slug', 'logo'], array_keys($response->json('brands.1')));
        $this->assertStringStartsWith('/api/workspace/brands/2/logo?v=', $response->json('brands.1.logo'));
        $response->assertDontSee('Private Third')->assertDontSee('manager-followup')->assertDontSee('DXF')->assertDontSee('logo_path');
        $this->get('/api/workspace/brands/2/logo', ['Accept' => 'application/json'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->b->update(['is_active' => false]);
        $this->getJson('/api/workspace/brands/2/logo')->assertNotFound();
        $this->getJson('/api/companies')->assertUnauthorized();
        $this->getJson('/api/workers')->assertUnauthorized();
    }

    public function test_client_and_employee_requests_with_the_same_email_do_not_replace_each_other(): void
    {
        $this->postJson('/api/register', $this->application(['is_employee' => true, 'last_name' => 'Worker surname', 'job_title' => 'Welder']))->assertStatus(202);
        $this->postJson('/api/register', $this->application())->assertStatus(202);
        $this->assertDatabaseCount('registration_requests', 2);
        $this->assertDatabaseHas('registration_requests', ['type' => 'employee', 'last_name' => 'Worker surname', 'job_title' => 'Welder', 'status' => 'pending']);
        $this->assertDatabaseHas('registration_requests', ['type' => 'client', 'job_title' => null, 'status' => 'pending']);
        $this->actingManager();
        $this->getJson('/api/registration-requests')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('counts.pending', 2);
        $this->getJson('/api/registration-requests?type=employee')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'employee');
        $employee = RegistrationRequest::where('type', 'employee')->firstOrFail();
        $client = RegistrationRequest::where('type', 'client')->firstOrFail();
        $this->postJson('/api/registration-requests/' . $client->id . '/approve')->assertOk();
        $this->postJson('/api/registration-requests/' . $employee->id . '/approve', ['role_id' => $this->role('engineer')])->assertUnprocessable();
        $this->assertSame('pending', $employee->fresh()->status);
        $this->assertSame(1, User::where('email', 'followup@example.invalid')->count());
    }

    public function test_approval_sends_localized_mail_without_password_and_repeat_notify_does_not_duplicate_it(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'workspace@example.invalid']);
        Mail::fake();
        $this->postJson('/api/register', $this->application(), ['X-Locale' => 'ru'])->assertStatus(202);
        $application = RegistrationRequest::firstOrFail();
        $this->actingManager();
        $this->postJson('/api/registration-requests/' . $application->id . '/approve')->assertOk()->assertJsonPath('notification_status', 'sent');
        $this->assertNotNull($application->fresh()->notification_sent_at);
        Mail::assertSent(RegistrationApproved::class, function ($mail) {
            $html = $mail->render();
            $this->assertStringContainsString('Регистрация', $mail->subject);
            $this->assertStringContainsString('/work/ru/login/', $html);
            $this->assertStringNotContainsString('new-password', $html);
            return $mail->hasTo('followup@example.invalid') && $mail->companyName === 'MetalWorks';
        });
        $this->postJson('/api/registration-requests/' . $application->id . '/notify')->assertOk()->assertJsonPath('notification_status', 'sent');
        Mail::assertSent(RegistrationApproved::class, 1);
    }

    public function test_mail_failure_preserves_approval_and_can_be_retried(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'workspace@example.invalid']);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->postJson('/api/register', $this->application())->assertStatus(202);
        $application = RegistrationRequest::firstOrFail();
        $this->actingManager();
        $this->postJson('/api/registration-requests/' . $application->id . '/approve')->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('notification_status', 'failed');
        $this->assertDatabaseHas('users', ['email' => 'followup@example.invalid']);
        $this->assertSame('approved', $application->fresh()->status);
        Mail::fake();
        $this->postJson('/api/registration-requests/' . $application->id . '/notify')->assertOk()->assertJsonPath('notification_status', 'sent');
        Mail::assertSent(RegistrationApproved::class, 1);
    }

    public function test_non_delivering_mailer_is_not_reported_as_sent_and_foreign_manager_cannot_retry(): void
    {
        config(['mail.default' => 'array']);
        Mail::fake();
        $this->postJson('/api/register', $this->application())->assertStatus(202);
        $application = RegistrationRequest::firstOrFail();
        $this->actingManager();
        $this->postJson('/api/registration-requests/' . $application->id . '/approve')->assertOk()->assertJsonPath('notification_status', 'unconfigured');
        Mail::assertNothingSent();
        $other = $this->account($this->b, 'manager-b-followup@example.invalid', 'manager');
        Sanctum::actingAs($other);
        $this->postJson('/api/registration-requests/' . $application->id . '/notify', [], ['X-Company-ID' => $this->b->id])->assertNotFound();
    }

    public function test_manager_can_assign_an_employee_as_a_client_in_another_managed_company(): void
    {
        $person = $this->account($this->a, 'shared-worker-followup@example.invalid', 'engineer');
        $hash = $person->password;
        $original = CompanyMembership::where('user_id', $person->id)->firstOrFail();
        $permission = Permission::where('slug', 'pmp.view')->firstOrFail();
        $original->permissions()->attach($permission->id, ['allowed' => true]);
        $this->manageSecond(); $this->actingManager();
        $options = $this->getJson('/api/company-access/' . $person->id)->assertOk()->assertJsonCount(2, 'companies');
        $this->assertSame([['id' => $this->c->id, 'name' => $this->c->name]], $options->json('unmanaged_companies'));
        $this->assertNotContains('admin', array_column($options->json('roles'), 'name'));
        $this->putJson('/api/company-access/' . $person->id, ['access' => [$this->access($this->b, 'authenticatedUser')]])->assertOk();
        $this->assertDatabaseHas('clients', ['company_id' => $this->b->id, 'user_id' => $person->id, 'phone' => '']);
        $this->assertDatabaseHas('company_memberships', ['company_id' => $this->b->id, 'user_id' => $person->id, 'role_id' => $this->role('authenticatedUser'), 'is_active' => true]);
        $this->assertSame($hash, $person->fresh()->password);
        $this->assertSame($this->role('engineer'), $original->fresh()->role_id);
        $this->assertDatabaseHas('membership_permissions', ['membership_id' => $original->id, 'permission_id' => $permission->id, 'allowed' => true]);
        Sanctum::actingAs($person);
        $this->getJson('/api/user', ['X-Company-ID' => $this->b->id])->assertOk()->assertJsonPath('role.name', 'authenticatedUser')->assertJsonCount(2, 'companies');
        $this->getJson('/api/user', ['X-Company-ID' => $this->a->id])->assertOk()->assertJsonPath('role.name', 'engineer')->assertJsonPath('permissions.0', 'pmp.view');
    }

    public function test_client_company_editor_cannot_assign_employee_positions_or_workshops(): void
    {
        $person = $this->account($this->a, 'client-followup@example.invalid', 'authenticatedUser');
        $this->manageSecond(); $this->actingManager();
        $factory = Factory::withoutGlobalScope('company')->where('company_id', $this->b->id)->firstOrFail();
        $row = $this->access($this->b, 'laser', $factory->id);
        $url = '/api/company-access/' . $person->id;
        $response = $this->getJson($url)->assertOk()->assertJsonPath('user.type', 'client')->assertJsonCount(0, 'roles')->assertJsonCount(0, 'companies.1.factories');
        foreach ($response->json('companies') as $company) {
            $this->assertSame(['company_id', 'enabled'], array_keys($company['access']));
        }
        $this->putJson($url, ['access' => [$row]])->assertUnprocessable();
        $this->putJson($url, ['access' => [$this->access($this->b, 'authenticatedUser', $factory->id)]])->assertUnprocessable();
        $this->putJson($url, ['access' => [['company_id' => $this->b->id, 'enabled' => true]]])->assertOk();
        $this->assertDatabaseMissing('workers', ['company_id' => $this->b->id, 'user_id' => $person->id]);
        $this->assertDatabaseHas('clients', ['company_id' => $this->b->id, 'user_id' => $person->id]);
        $this->assertDatabaseHas('company_memberships', ['company_id' => $this->b->id, 'user_id' => $person->id, 'role_id' => $this->role('authenticatedUser'), 'factory_id' => null]);
        $this->assertDatabaseHas('company_memberships', ['company_id' => $this->a->id, 'user_id' => $person->id, 'role_id' => $this->role('authenticatedUser')]);
    }

    public function test_unmanaged_company_role_escalation_and_foreign_workshop_are_rejected_atomically(): void
    {
        $person = $this->account($this->a, 'checked-followup@example.invalid', 'engineer');
        $this->manageSecond(); $this->actingManager();
        $url = '/api/company-access/' . $person->id;
        $this->putJson($url, ['access' => [$this->access($this->b, 'engineer'), $this->access($this->c, 'engineer')]])->assertForbidden();
        $this->assertDatabaseMissing('company_memberships', ['user_id' => $person->id, 'company_id' => $this->b->id]);
        $this->putJson($url, ['access' => [$this->access($this->b, 'admin')]])->assertUnprocessable();
        $factory = Factory::withoutGlobalScope('company')->where('company_id', $this->a->id)->firstOrFail();
        $this->putJson($url, ['access' => [$this->access($this->b, 'laser', $factory->id)]])->assertUnprocessable();
        $this->putJson($url, ['access' => [$this->access($this->b, 'laser')]])->assertUnprocessable();
        $this->putJson($url, ['access' => [$this->access($this->a, 'laser', $factory->id)]])->assertOk();
        $this->assertDatabaseMissing('company_memberships', ['user_id' => $person->id, 'company_id' => $this->b->id]);
    }

    public function test_only_managed_companies_are_listed_and_foreign_targets_are_hidden(): void
    {
        $person = $this->account($this->a, 'own-followup@example.invalid', 'engineer');
        $other = $this->account($this->b, 'foreign-followup@example.invalid', 'engineer');
        CompanyMembership::create(['company_id' => $this->b->id, 'user_id' => $this->manager->id, 'role_id' => $this->role('engineer')]);
        $this->actingManager();
        $this->getJson('/api/company-access/' . $person->id)->assertOk()->assertJsonCount(1, 'companies');
        $this->getJson('/api/company-access/' . $other->id)->assertNotFound();
        $this->putJson('/api/company-access/' . $person->id, ['access' => [$this->access($this->b, 'engineer')]])->assertForbidden();
        Sanctum::actingAs($person);
        $this->getJson('/api/company-access/' . $this->manager->id)->assertForbidden();
    }

    public function test_role_changes_and_reactivation_clear_only_destination_permissions(): void
    {
        $person = $this->account($this->a, 'grant-followup@example.invalid', 'engineer');
        $membership = CompanyMembership::create(['company_id' => $this->b->id, 'user_id' => $person->id, 'role_id' => $this->role('engineer'), 'is_active' => false]);
        $permission = Permission::where('slug', 'pmp.view')->firstOrFail();
        $membership->permissions()->attach($permission->id, ['allowed' => true]);
        $this->manageSecond(); $this->actingManager();
        $this->putJson('/api/company-access/' . $person->id, ['access' => [$this->access($this->b, 'engineer')]])->assertOk();
        $this->assertDatabaseMissing('membership_permissions', ['membership_id' => $membership->id]);
        $membership->permissions()->attach($permission->id, ['allowed' => true]);
        $this->putJson('/api/company-access/' . $person->id, ['access' => [$this->access($this->b, 'authenticatedUser')]])->assertOk();
        $this->assertDatabaseMissing('membership_permissions', ['membership_id' => $membership->id]);
    }

    public function test_managers_and_company_admins_review_all_managed_company_requests_in_one_list(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'workspace@example.invalid']);
        Mail::fake();
        foreach ([$this->a, $this->b] as $company) {
            $this->postJson('/api/register', $this->application(['email' => 'client-'.$company->id.'@example.invalid', 'company_id' => $company->id]))->assertStatus(202);
            $this->postJson('/api/register', $this->application(['email' => 'staff-'.$company->id.'@example.invalid', 'company_id' => $company->id, 'is_employee' => true, 'last_name' => 'Surname', 'job_title' => 'Welder']))->assertStatus(202);
        }
        $this->postJson('/api/register', $this->application(['email' => 'private@example.invalid', 'company_id' => $this->c->id]))->assertStatus(202);
        $this->manageSecond();
        foreach (['manager', 'admin'] as $role) {
            $membership = CompanyMembership::where('user_id', $this->manager->id)->where('company_id', $this->a->id)->firstOrFail();
            app(CompanyContext::class)->run($this->a, fn () => \App\Support\MembershipAssignments::sync($membership, [['role_id' => $this->role($role), 'factory_id' => null]]));
            $this->actingManager();
            $this->getJson('/api/registration-requests')->assertOk()->assertJsonCount(4, 'data')->assertJsonCount(2, 'companies')->assertJsonPath('counts.pending', 4)->assertDontSee('private@example.invalid')->assertJsonPath('unmanaged_companies.0.id', $this->c->id);
            $this->getJson('/api/registration-requests?type=employee')->assertOk()->assertJsonCount(2, 'data');
            $this->getJson('/api/registration-requests?company_id='.$this->b->id)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('counts.pending', 2);
        }
        $application = RegistrationRequest::where('email', 'staff-'.$this->b->id.'@example.invalid')->firstOrFail();
        $factory = Factory::withoutGlobalScope('company')->where('company_id', $this->b->id)->firstOrFail();
        $options = $this->getJson('/api/registration-requests/options?company_id='.$this->b->id)->assertOk();
        $this->assertContains($factory->id, array_column($options->json('factories'), 'id'));
        $this->postJson('/api/registration-requests/'.$application->id.'/approve', ['assignments' => [['role_id' => $this->role('laser'), 'factory_id' => $factory->id]]])->assertOk()->assertJsonPath('notification_status', 'sent');
        Mail::assertSent(RegistrationApproved::class, 1);
        $this->assertDatabaseHas('workers', ['company_id' => $this->b->id, 'user_id' => $application->fresh()->user_id]);
        $this->assertDatabaseMissing('company_memberships', ['company_id' => $this->a->id, 'user_id' => $application->fresh()->user_id]);
        $private = RegistrationRequest::where('email', 'private@example.invalid')->firstOrFail();
        $this->getJson('/api/registration-requests?company_id='.$this->c->id)->assertForbidden();
        $this->getJson('/api/registration-requests/options?company_id='.$this->c->id)->assertForbidden();
        $this->postJson('/api/registration-requests/'.$private->id.'/approve')->assertNotFound();
        $this->postJson('/api/registration-requests/'.$private->id.'/reject')->assertNotFound();
    }

    public function test_admin_grant_makes_other_company_clients_workshops_and_both_request_types_available_to_manager(): void
    {
        foreach (['client', 'employee'] as $type) {
            $this->postJson('/api/register', $this->application(['email' => $type.'-second@example.invalid', 'company_id' => $this->b->id, 'is_employee' => $type === 'employee', 'last_name' => 'Surname', 'job_title' => 'Welder']))->assertStatus(202);
        }
        $client = $this->account($this->a, 'directory-client@example.invalid', 'authenticatedUser');
        $this->actingManager();
        $this->getJson('/api/registration-requests?status=all')->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(1, 'companies');
        $directory = $this->getJson('/api/company-access/'.$client->id)->assertOk()->assertJsonCount(1, 'companies')->json('unmanaged_companies');
        $this->assertSame(['id', 'name'], array_keys($directory[0]));
        $this->putJson('/api/company-access/'.$client->id, ['access' => [['company_id' => $this->b->id, 'enabled' => true]]])->assertForbidden();

        $admin = $this->account($this->a, 'directory-admin@example.invalid', 'admin');
        $admin->forceFill(['is_platform_admin' => true])->save();
        Sanctum::actingAs($admin);
        $this->putJson('/api/company-access/'.$this->manager->id, ['access' => [['company_id' => $this->b->id, 'enabled' => true, 'assignments' => [['role_id' => $this->role('manager'), 'factory_id' => null]]]]])->assertOk();

        $this->actingManager();
        $this->getJson('/api/registration-requests?status=all')->assertOk()->assertJsonCount(2, 'data')->assertJsonCount(2, 'companies')->assertJsonPath('type_counts.client', 1)->assertJsonPath('type_counts.employee', 1);
        $this->getJson('/api/staff/worker-options')->assertOk()->assertJsonPath('can_manage_companies', true)->assertJsonCount(2, 'companies')->assertJsonCount(6, 'companies.1.factories');
        $this->putJson('/api/company-access/'.$client->id, ['access' => [['company_id' => $this->b->id, 'enabled' => true]]])->assertOk();
        $this->assertDatabaseHas('clients', ['user_id' => $client->id, 'company_id' => $this->b->id]);
        CompanyMembership::where('user_id', $this->manager->id)->where('company_id', $this->b->id)->update(['is_active' => false]);
        $this->getJson('/api/registration-requests?status=all')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/registration-requests/options?company_id='.$this->b->id)->assertForbidden();
    }

    public function test_all_statuses_and_type_counts_retain_reviewed_employee_requests_and_company_filters(): void
    {
        $this->manageSecond();
        foreach ([$this->a, $this->b, $this->c] as $company) {
            RateLimiter::clear('register|127.0.0.1');
            $this->postJson('/api/register', $this->application(['company_id' => $company->id, 'email' => 'all-client-'.$company->id.'@example.invalid']))->assertStatus(202);
            $this->postJson('/api/register', $this->application(['company_id' => $company->id, 'email' => 'all-staff-'.$company->id.'@example.invalid', 'is_employee' => true, 'last_name' => 'Surname', 'job_title' => 'Welder']))->assertStatus(202);
        }
        $this->actingManager();
        $employee = RegistrationRequest::withoutGlobalScope('company')->where('company_id', $this->b->id)->where('type', 'employee')->firstOrFail();
        $client = RegistrationRequest::withoutGlobalScope('company')->where('company_id', $this->b->id)->where('type', 'client')->firstOrFail();
        $this->postJson('/api/registration-requests/'.$employee->id.'/approve', ['role_id' => $this->role('engineer')])->assertOk();
        $this->postJson('/api/registration-requests/'.$client->id.'/reject')->assertOk();
        $this->getJson('/api/registration-requests')->assertOk()->assertJsonCount(2, 'data'); // Existing API callers keep the pending default.
        $this->getJson('/api/registration-requests?status=all')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('type_counts.employee', 2)->assertJsonPath('type_counts.client', 2)->assertJsonPath('counts.approved', 1)->assertJsonPath('counts.rejected', 1)->assertDontSee('all-staff-'.$this->c->id.'@example.invalid');
        $this->getJson('/api/registration-requests?status=all&type=employee&company_id='.$this->b->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'approved')->assertJsonPath('type_counts.employee', 1)->assertJsonPath('type_counts.client', 1);
        $this->getJson('/api/registration-requests?status=approved')->assertOk()->assertJsonPath('type_counts.employee', 1)->assertJsonPath('type_counts.client', 0);
    }

    public function test_manager_worker_form_saves_each_company_workshops_and_rejects_foreign_access_atomically(): void
    {
        $person = $this->account($this->a, 'worker-form-scopes@example.invalid', 'engineer');
        $this->account($this->c, 'foreign-worker-hidden@example.invalid', 'laser');
        $this->manageSecond(); $this->actingManager();
        $source = CompanyMembership::where('user_id', $person->id)->where('company_id', $this->a->id)->firstOrFail();
        $permission = Permission::where('slug', 'pmp.view')->firstOrFail();
        $source->permissions()->attach($permission->id, ['allowed' => true]);
        $factories = Factory::withoutGlobalScope('company')->where('company_id', $this->b->id)->orderBy('id')->take(2)->get();
        $positions = $factories->map(fn ($factory) => ['role_id' => $this->role('laser'), 'factory_id' => $factory->id])->all();
        $payload = ['name' => $person->name, 'email' => $person->email, 'phone' => '123', 'assignments' => [['role_id' => $this->role('engineer'), 'factory_id' => null]], 'company_access' => [['company_id' => $this->b->id, 'enabled' => true, 'assignments' => $positions]]];
        $response = $this->putJson('/api/workers/'.$person->id, $payload)->assertOk()->assertJsonCount(2, 'data.company_access');
        $this->assertSame(2, CompanyMembership::where('user_id', $person->id)->where('company_id', $this->b->id)->firstOrFail()->assignments()->count());
        $this->assertDatabaseHas('membership_permissions', ['membership_id' => $source->id, 'permission_id' => $permission->id, 'allowed' => true]);
        $hash = $person->fresh()->password;
        $payload['company_access'][0]['assignments'][0]['factory_id'] = Factory::withoutGlobalScope('company')->where('company_id', $this->a->id)->value('id');
        $this->putJson('/api/workers/'.$person->id, $payload)->assertUnprocessable();
        $payload['company_access'] = [['company_id' => $this->c->id, 'enabled' => true, 'role_id' => $this->role('engineer')]];
        $payload['phone'] = 'rollback-phone';
        $this->putJson('/api/workers/'.$person->id, $payload)->assertForbidden();
        $this->assertDatabaseMissing('workers', ['user_id' => $person->id, 'phone' => 'rollback-phone']);
        $this->assertSame($hash, $person->fresh()->password);
        $payload['company_access'] = [['company_id' => $this->b->id, 'enabled' => true, 'role_id' => $this->role('admin')]];
        $this->putJson('/api/workers/'.$person->id, $payload)->assertUnprocessable();
        $this->assertSame(2, CompanyMembership::where('user_id', $person->id)->where('company_id', $this->b->id)->firstOrFail()->assignments()->count());
    }

    public function test_access_from_another_managed_company_edits_that_company_without_switching_the_workspace(): void
    {
        $person = $this->account($this->b, 'other-source@example.invalid', 'engineer');
        $this->manageSecond(); $this->actingManager();
        $url = '/api/company-access/'.$person->id.'?source_company_id='.$this->b->id;
        $this->getJson('/api/company-access/'.$person->id)->assertNotFound();
        $this->getJson($url)->assertOk()->assertJsonPath('current_company_id', $this->b->id)->assertJsonPath('user.type', 'employee');
        $factories = Factory::withoutGlobalScope('company')->where('company_id', $this->b->id)->orderBy('id')->take(2)->get();
        $assignments = [['role_id' => $this->role('engineer'), 'factory_id' => null], ...$factories->map(fn ($factory) => ['role_id' => $this->role('laser'), 'factory_id' => $factory->id])->all()];
        $this->putJson($url, ['access' => [['company_id' => $this->b->id, 'enabled' => true, 'assignments' => $assignments]]])->assertOk();
        $membership = CompanyMembership::where('user_id', $person->id)->where('company_id', $this->b->id)->firstOrFail();
        $this->assertSame(3, $membership->assignments()->count());
        $this->assertDatabaseMissing('company_memberships', ['company_id' => $this->a->id, 'user_id' => $person->id]);
        $this->getJson('/api/company-access/'.$person->id.'?source_company_id='.$this->c->id)->assertForbidden();
        CompanyMembership::where('user_id', $this->manager->id)->where('company_id', $this->b->id)->update(['is_active' => false]);
        $this->getJson($url)->assertForbidden();
        $this->putJson($url, ['access' => [['company_id' => $this->a->id, 'enabled' => true, 'role_id' => $this->role('manager')]]])->assertForbidden();
        $this->assertSame(3, $membership->assignments()->count());
    }

    public function test_client_editor_preserves_existing_employee_access_in_another_company(): void
    {
        $person = $this->account($this->a, 'mixed-access@example.invalid', 'authenticatedUser');
        $membership = CompanyMembership::create(['company_id' => $this->b->id, 'user_id' => $person->id, 'role_id' => $this->role('engineer')]);
        $this->manageSecond(); $this->actingManager();
        $url = '/api/company-access/'.$person->id;
        $this->getJson($url)->assertOk()->assertJsonPath('companies.1.read_only', true);
        $this->putJson($url, ['access' => [['company_id' => $this->b->id, 'enabled' => false]]])->assertForbidden();
        $this->putJson($url, ['access' => [$this->access($this->b, 'authenticatedUser')]])->assertForbidden();
        $this->assertSame($this->role('engineer'), $membership->fresh()->role_id);
        $this->assertTrue($membership->fresh()->is_active);
    }

    public function test_localized_approval_email_has_a_login_button_and_neutral_copy(): void
    {
        foreach (['hy', 'ru', 'en'] as $locale) {
            $mail = new RegistrationApproved('QA Person', 'Second Works', $locale);
            $html = $mail->render();
            $this->assertStringContainsString('Second Works', $html);
            $this->assertStringNotContainsString('Laravel', $html);
            $this->assertStringContainsString('/work/'.($locale === 'hy' ? '' : $locale.'/').'login/', $html);
            $this->assertDoesNotMatchRegularExpression('/manager|менеджер|մենեջեր/ui', $html);
            $this->assertStringNotContainsString('new-password', $html);
        }
    }

    private function actingManager(): void { Sanctum::actingAs($this->manager); $this->withHeader('X-Company-ID', (string) $this->a->id); }
    private function manageSecond(): void { CompanyMembership::create(['company_id' => $this->b->id, 'user_id' => $this->manager->id, 'role_id' => $this->role('manager')]); }
    private function role(string $name): int { return (int) Role::where('name', $name)->value('id'); }
    private function account(Company $company, string $email, string $role): User { return app(CompanyContext::class)->run($company, fn () => User::create(['name' => 'Existing Person', 'last_name' => 'Surname', 'email' => $email, 'password' => 'existing-password', 'role_id' => $this->role($role)])); }
    private function access(Company $company, string $role, ?int $factory = null): array { return ['company_id' => $company->id, 'enabled' => true, 'role_id' => $this->role($role), 'factory_id' => $factory]; }
    private function application(array $overrides = []): array { return array_merge(['name' => 'New Person', 'email' => 'followup@example.invalid', 'password' => 'new-password', 'password_confirmation' => 'new-password', 'company_id' => $this->a->id, 'is_employee' => false], $overrides); }
}
