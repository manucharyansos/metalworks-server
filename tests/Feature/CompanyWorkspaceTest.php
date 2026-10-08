<?php

namespace Tests\Feature;

use App\Models\{Company, CompanyMembership, Factory, File, Order, Permission, Pmp, Role, User};
use App\Support\{CompanyContext, OrderNumberGenerator};
use Database\Seeders\{FactorySeeder, RoleTableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private User $owner;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleTableSeeder::class);
        $this->a = Company::where('slug', 'metalworks')->firstOrFail();
        $this->b = Company::create(['name' => 'Second Works', 'slug' => 'second']);
        foreach ([$this->a, $this->b] as $c) $this->in($c, fn () => (new FactorySeeder)->run());
        $this->owner = $this->account($this->a, 'owner@example.invalid', 'admin');
        $this->owner->forceFill(['is_platform_admin' => true])->save();
        $this->admin = $this->account($this->a, 'local@example.invalid', 'admin');
        Storage::fake('public'); Storage::fake('private');
    }

    public function test_directory_and_header_cannot_expose_an_unassigned_company(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/companies')->assertOk()->assertJsonCount(1, 'companies')->assertJsonPath('companies.0.id', $this->a->id);
        $this->getJson('/api/user', ['X-Company-ID' => $this->b->id])->assertForbidden();
        $this->getJson('/api/user', ['X-Company-ID' => $this->a->id])->assertOk()->assertJsonPath('company.id', $this->a->id)->assertJsonPath('role.name', 'admin');
        $this->assertNull(app(CompanyContext::class)->id());
        $this->postJson('/api/companies', ['name' => 'Secret', 'slug' => 'secret'])->assertForbidden();
    }

    public function test_binding_lists_dashboards_and_files_use_the_selected_company(): void
    {
        $one = $this->order($this->a, $this->admin, 'A task');
        $other = $this->account($this->b, 'other@example.invalid', 'admin');
        $two = $this->order($this->b, $other, 'B task');
        $file = $this->in($this->b, fn () => File::create(['order_id' => $two->id, 'path' => 'orders/b.txt', 'original_name' => 'b.txt']));
        Storage::disk('private')->put($file->path, 'B private data');
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/order/' . $two->id)->assertNotFound();
        $this->getJson('/api/admin/order')->assertOk()->assertSee('A task')->assertDontSee('B task');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('summary.total_orders', 1);
        $this->getJson('/api/secure-files/order/' . $file->id)->assertNotFound();
        $this->getJson('/api/secure-files/path/orders/b.txt')->assertNotFound();
        Sanctum::actingAs($other);
        $this->get('/api/secure-files/order/' . $file->id, ['Accept' => 'application/json'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/admin/order/' . $one->id)->assertNotFound();
    }

    public function test_shared_account_has_separate_roles_workshops_and_grants(): void
    {
        $user = $this->account($this->a, 'shared@example.invalid', 'engineer');
        $workshop = $this->in($this->b, fn () => Factory::where('value', 'DXF')->firstOrFail());
        $membership = CompanyMembership::create(['company_id' => $this->b->id, 'user_id' => $user->id, 'role_id' => Role::where('name', 'laser')->value('id'), 'factory_id' => $workshop->id]);
        $permission = Permission::where('slug', 'factory.download')->firstOrFail();
        $membership->permissions()->attach($permission->id, ['allowed' => true]);
        Sanctum::actingAs($user);
        $this->getJson('/api/user', ['X-Company-ID' => $this->a->id])->assertOk()->assertJsonPath('role.name', 'engineer')->assertJsonPath('factory_id', null)->assertJsonPath('permissions', []);
        $this->getJson('/api/user', ['X-Company-ID' => $this->b->id])->assertOk()->assertJsonPath('role.name', 'laser')->assertJsonPath('factory_id', $workshop->id)->assertJsonPath('permissions.0', 'factory.download');
        $this->getJson('/api/workers')->assertStatus(409);
        $membership->update(['is_active' => false]);
        $this->getJson('/api/user', ['X-Company-ID' => $this->b->id])->assertForbidden();
        $this->getJson('/api/user')->assertOk()->assertJsonCount(1, 'companies');
    }

    public function test_cross_company_workshop_is_rejected_without_writes(): void
    {
        $factory = $this->in($this->b, fn () => Factory::firstOrFail());
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/workers', $this->workerPayload(['role_id' => Role::where('name', 'laser')->value('id'), 'factory_id' => $factory->id]), ['X-Company-ID' => $this->a->id])->assertUnprocessable()->assertJsonValidationErrors('factory_id');
        $this->assertDatabaseMissing('users', ['email' => 'worker@example.invalid']);
        $this->postJson('/api/admin/factory-file-extensions', ['factory_id' => $factory->id, 'extension' => 'qa'], ['X-Company-ID' => $this->a->id])->assertUnprocessable();
    }

    public function test_owner_creates_empty_enterprise_and_scoped_configuration(): void
    {
        $this->order($this->a, $this->admin, 'Existing');
        Sanctum::actingAs($this->owner);
        $response = $this->postJson('/api/companies', ['name' => 'Third Works', 'slug' => 'THIRD'])->assertCreated();
        $id = $response->json('company.id');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('companies', ['id' => $id, 'slug' => 'third']);
        $this->assertSame(6, Factory::withoutGlobalScope('company')->where('company_id', $id)->count());
        $this->getJson('/api/user', ['X-Company-ID' => $id])->assertOk()->assertJsonPath('company.id', $id);
        $this->postJson('/api/companies', ['name' => 'Duplicate', 'slug' => 'THIRD'])->assertUnprocessable();
    }

    public function test_per_company_checkboxes_and_revocation_preserve_other_employment(): void
    {
        Sanctum::actingAs($this->owner);
        $factory = $this->in($this->b, fn () => Factory::where('value', 'DXF')->firstOrFail());
        $payload = $this->workerPayload(['company_access' => [
            ['company_id' => $this->a->id, 'enabled' => true, 'role_id' => Role::where('name', 'engineer')->value('id')],
            ['company_id' => $this->b->id, 'enabled' => true, 'role_id' => Role::where('name', 'laser')->value('id'), 'factory_id' => $factory->id],
        ]]);
        $response = $this->postJson('/api/workers', $payload, ['X-Company-ID' => $this->a->id])->assertCreated();
        $id = $response->json('id') ?? $response->json('data.id');
        $this->assertNotNull($id);
        $this->assertDatabaseHas('company_memberships', ['user_id' => $id, 'company_id' => $this->b->id, 'factory_id' => $factory->id, 'is_active' => true]);
        $this->deleteJson('/api/workers/' . $id, [], ['X-Company-ID' => $this->a->id])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $id]);
        $this->assertDatabaseHas('company_memberships', ['user_id' => $id, 'company_id' => $this->b->id, 'is_active' => true]);
        Sanctum::actingAs(User::findOrFail($id));
        $this->getJson('/api/user', ['X-Company-ID' => $this->a->id])->assertForbidden();
        $this->getJson('/api/user', ['X-Company-ID' => $this->b->id])->assertOk()->assertJsonPath('role.name', 'laser');
    }

    public function test_company_admin_cannot_grant_other_company_access_or_platform_privileges(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/workers', $this->workerPayload(['company_access' => [['company_id' => $this->b->id, 'enabled' => true, 'role_id' => $this->admin->role_id]]]))->assertForbidden();
        $this->postJson('/api/workers', $this->workerPayload(['is_platform_admin' => true]))->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'worker@example.invalid', 'is_platform_admin' => false]);
    }

    public function test_group_codes_and_number_sequences_are_independent(): void
    {
        foreach ([$this->a, $this->b] as $company) {
            $this->in($company, fn () => Pmp::create(['group' => '111', 'group_name' => 'Private group', 'admin_confirmation' => false]));
            $this->assertStringEndsWith('-0001', $this->in($company, fn () => OrderNumberGenerator::next()));
            $this->assertStringEndsWith('-0002', $this->in($company, fn () => OrderNumberGenerator::next()));
        }
        $this->assertSame(1, $this->in($this->a, fn () => Pmp::count()));
        $this->assertDatabaseCount('order_number_sequences', 2);
    }

    public function test_existing_identity_can_be_a_client_elsewhere_without_changing_employee_access(): void
    {
        $person = $this->account($this->a, 'person@example.invalid', 'engineer');
        $hash = $person->password;
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/clients/client', ['name' => 'Company B Client', 'email' => $person->email, 'phone' => '091222222', 'type' => 'physPerson'], ['X-Company-ID' => $this->b->id])->assertCreated();
        $this->getJson('/api/clients/client/' . $person->id, ['X-Company-ID' => $this->b->id])->assertOk()->assertJsonPath('data.name', 'Company B Client');
        $this->getJson('/api/clients/client/' . $person->id, ['X-Company-ID' => $this->a->id])->assertNotFound();
        $this->deleteJson('/api/clients/client/' . $person->id, [], ['X-Company-ID' => $this->b->id])->assertOk();
        $this->assertSame($hash, $person->fresh()->password);
        Sanctum::actingAs($person);
        $this->getJson('/api/user', ['X-Company-ID' => $this->a->id])->assertOk()->assertJsonPath('role.name', 'engineer');
        $this->getJson('/api/user', ['X-Company-ID' => $this->b->id])->assertForbidden();
    }

    public function test_permission_changes_do_not_affect_other_company_grants(): void
    {
        $person = $this->account($this->a, 'grants@example.invalid', 'engineer');
        $other = CompanyMembership::create(['company_id' => $this->b->id, 'user_id' => $person->id, 'role_id' => Role::where('name', 'engineer')->value('id')]);
        $permission = Permission::where('slug', 'pmp.view')->firstOrFail();
        $other->permissions()->attach($permission->id, ['allowed' => true]);
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/users/' . $person->id . '/permissions', ['permissions' => []], ['X-Company-ID' => $this->a->id])->assertOk();
        $this->assertDatabaseHas('membership_permissions', ['membership_id' => $other->id, 'permission_id' => $permission->id]);
    }

    public function test_public_registration_has_no_company_access(): void
    {
        $this->getJson('/api/companies')->assertUnauthorized();
        $this->postJson('/api/register', ['name' => 'New person', 'email' => 'new@example.invalid', 'password' => 'test-password', 'password_confirmation' => 'test-password', 'company_id' => $this->a->id])->assertCreated();
        $user = User::where('email', 'new@example.invalid')->firstOrFail();
        $this->assertSame(0, $user->memberships()->count());
        Sanctum::actingAs($user);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('company', null)->assertJsonPath('companies', [])->assertJsonPath('permissions', []);
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('capabilities.client_orders', false);
        $this->getJson('/api/workers')->assertForbidden();
    }

    public function test_privatization_verifies_content_and_preserves_unrelated_public_assets(): void
    {
        Storage::disk('public')->put('orders/legacy.txt', 'old private upload');
        Storage::disk('public')->put('PMP_old/abandoned.txt', 'orphan drawing');
        Storage::disk('public')->put('website/banner.png', 'public marketing');
        $this->artisan('files:privatize-workspaces')->assertSuccessful();
        Storage::disk('public')->assertExists('orders/legacy.txt');
        $this->artisan('files:privatize-workspaces --apply')->assertSuccessful();
        Storage::disk('private')->assertExists('orders/legacy.txt');
        $this->assertSame('old private upload', Storage::disk('private')->get('orders/legacy.txt'));
        Storage::disk('public')->assertMissing('orders/legacy.txt');
        Storage::disk('public')->assertMissing('PMP_old/abandoned.txt');
        Storage::disk('public')->assertExists('website/banner.png');
        Storage::disk('public')->put('orders/mismatch.txt', 'source');
        Storage::disk('private')->put('orders/mismatch.txt', 'different copy');
        $this->artisan('files:privatize-workspaces --apply')->assertFailed();
        Storage::disk('public')->assertExists('orders/mismatch.txt');
        $this->assertSame('different copy', Storage::disk('private')->get('orders/mismatch.txt'));
    }

    private function in(Company $company, callable $fn): mixed { return app(CompanyContext::class)->run($company, $fn); }

    private function account(Company $company, string $email, string $role): User
    {
        return $this->in($company, fn () => User::create(['name' => 'Test Person', 'email' => $email, 'password' => 'test-password', 'role_id' => Role::where('name', $role)->value('id')]));
    }

    private function order(Company $company, User $user, string $name): Order
    {
        $customer = $this->account($company, 'customer-' . md5($name) . '@example.invalid', 'authenticatedUser');
        return $this->in($company, fn () => Order::create(['name' => $name, 'user_id' => $customer->id, 'creator_id' => $user->id]));
    }

    private function workerPayload(array $extra = []): array
    {
        return array_replace(['name' => 'Employee', 'email' => 'worker@example.invalid', 'phone' => '091000000', 'role_id' => Role::where('name', 'engineer')->value('id'), 'password' => 'test-password', 'password_confirmation' => 'test-password'], $extra);
    }
}
