<?php

namespace Tests\Feature;

use App\Models\{Company, CompanyMembership, Factory, FactoryOrder, Order, Permission, Pmp, PmpFiles, RemoteNumber, Role, User};
use App\Support\{CompanyContext, MembershipAssignments};
use Database\Seeders\{FactorySeeder, FactoryOrderStatusSeeder, RoleTableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Mail, Schema, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyTaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otherCompany;
    private User $engineer;
    private User $otherEngineer;
    private User $customer;
    private User $admin;
    private User $operator;
    private User $bendOperator;
    private Pmp $pmp;
    private array $factories;
    private array $files;
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
        $this->company = Company::where('slug', 'metalworks')->firstOrFail();
        $this->otherCompany = Company::create(['name' => 'Other Works', 'slug' => 'other-works']);
        foreach ([$this->company, $this->otherCompany] as $company) $this->in($company, function () { (new FactorySeeder)->run(); (new FactoryOrderStatusSeeder)->run(); });
        $this->factories = $this->in($this->company, fn () => Factory::get()->keyBy('value')->all());
        $this->engineer = $this->account('creator', 'engineer');
        $this->otherEngineer = $this->account('another', 'engineer');
        $this->admin = $this->account('admin', 'admin');
        $this->customer = $this->account('client', 'authenticatedUser');
        $this->operator = $this->account('laser', 'laser', $this->factories['DXF']->id);
        $this->bendOperator = $this->account('bend', 'bend', $this->factories['DLD']->id);
        $this->pmp = $this->in($this->company, fn () => Pmp::create(['group' => '990', 'group_name' => 'Task testing', 'admin_confirmation' => false]));
        Storage::fake('private'); Mail::fake();
        $this->files = $this->in($this->company, function () {
            $remote = RemoteNumber::create(['pmp_id' => $this->pmp->id, 'remote_number' => '01', 'remote_number_name' => 'QA']);
            $files = [];
            foreach (['DXF', 'DLD', 'INFO', 'PDF'] as $value) {
                $files[$value] = PmpFiles::create(['pmp_id' => $this->pmp->id, 'remote_number_id' => $remote->id, 'factory_id' => $this->factories[$value]->id, 'path' => 'companies/'.$this->company->id.'/qa/'.$value.'.txt', 'original_name' => $value.'.txt']);
                Storage::disk('private')->put($files[$value]->path, $value.' private data');
            }
            return $files;
        });
    }

    public function test_creation_requires_a_real_workshop_and_a_valid_confirmation_method(): void
    {
        $this->as($this->engineer);
        foreach ([['confirmation_required' => true], ['confirmation_required' => true, 'confirmation_method' => 'video']] as $extra) {
            $this->postJson('/api/engineers/engineer', $this->payload($extra), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('confirmation_method');
        }
        $this->postJson('/api/engineers/engineer', $this->payload(['selected_files' => [['id' => $this->files['INFO']->id, 'quantity' => 1]]]), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('selected_files');
        $this->assertDatabaseCount('orders', 0);
        Mail::assertNothingSent();
    }

    public function test_no_confirmation_task_completes_on_the_operator_action_and_preserves_creator_visibility(): void
    {
        $order = $this->createTask();
        $this->as($this->operator);
        $this->act($order, 'confirmed')->assertOk();
        $this->as($this->engineer);
        $this->getJson('/api/engineers/engineer', $this->headers())->assertOk()->assertJsonPath('orders.0.factory_orders.0.operator_id', $this->operator->id)->assertJsonPath('orders.0.factory_orders.0.status', 'confirmed');
        $this->as($this->operator);
        $this->act($order, 'finished', ['operator_finish_date' => '2099-01-01'])->assertOk()->assertJsonPath('status', 'completed');
        $step = $order->factoryOrders()->firstOrFail();
        $this->assertNotNull($step->completed_at);
        $this->assertNull($step->admin_confirmation_date);
        $this->assertNull($step->engineer_confirmation_at);
        $this->assertNotSame('2099-01-01', $step->operator_finish_date);
        $this->as($this->engineer);
        $this->getJson('/api/engineers/engineer', $this->headers())->assertOk()->assertJsonPath('orders.0.status', 'completed')->assertJsonCount(2, 'orders.0.logs');
        $this->getJson('/api/engineers/engineer?confirmation=waiting', $this->headers())->assertOk()->assertJsonCount(0, 'orders');
        $this->as($this->admin);
        $this->getJson('/api/admin/dashboard', $this->headers())->assertOk()->assertJsonPath('summary.awaiting_engineer_confirmation', 0)->assertJsonPath('summary.completed_orders', 1);
    }

    public function test_text_evidence_is_mandatory_and_only_the_creating_engineer_can_confirm(): void
    {
        $order = $this->createTask(['confirmation_required' => true, 'confirmation_method' => 'text']);
        $step = $order->factoryOrders()->firstOrFail();
        $this->as($this->engineer);
        $this->approve($step)->assertUnprocessable();
        $this->as($this->operator);
        $this->act($order, 'finished', ['evidence_text' => '  '])->assertUnprocessable()->assertJsonValidationErrors('factory_order.evidence_text');
        $this->assertSame('pending', $step->fresh()->status);
        $this->act($order, 'finished', ['evidence_text' => '  Checked dimensions and completed cutting.  '])->assertOk()->assertJsonPath('factory_orders.0.awaiting_engineer_confirmation', true);
        $this->assertSame('pending', $order->fresh()->status);
        $this->act($order, 'confirmed')->assertUnprocessable();
        $this->as($this->admin);
        $this->putJson('/api/factories/confirmOrderStatus/'.$order->id, ['factory_id' => $step->factory_id], $this->headers())->assertForbidden();
        $this->postJson('/api/admin/factory-orders/'.$step->id.'/confirm', [], $this->headers())->assertForbidden();
        $this->getJson('/api/admin/dashboard/orders?confirmation=waiting', $this->headers())->assertOk()->assertJsonCount(1, 'orders');
        $this->getJson('/api/admin/dashboard', $this->headers())->assertOk()->assertJsonPath('summary.awaiting_engineer_confirmation', 1);
        $this->as($this->otherEngineer);
        $this->approve($step)->assertForbidden();
        $this->getJson('/api/engineers/engineer/'.$order->id, $this->headers())->assertForbidden();
        $this->getJson('/api/orders/'.$order->id, $this->headers())->assertForbidden();
        $this->getJson('/api/engineers/engineer', $this->headers())->assertOk()->assertJsonCount(0, 'orders');
        $this->as($this->engineer);
        $this->getJson('/api/engineers/engineer?confirmation=waiting', $this->headers())->assertOk()->assertJsonPath('orders.0.factory_orders.0.evidence_text', 'Checked dimensions and completed cutting.');
        $this->approve($step)->assertOk()->assertJsonPath('order.status', 'completed');
        $stamp = $step->fresh()->engineer_confirmation_at;
        $this->approve($step)->assertOk();
        $this->assertSame($stamp, $step->fresh()->engineer_confirmation_at);
        $this->assertSame($this->engineer->id, (int) $step->fresh()->engineer_confirmation_user_id);
        $this->assertDatabaseCount('order_logs', 2);
    }

    public function test_photo_evidence_is_private_validated_and_cannot_be_replaced_after_submission(): void
    {
        $order = $this->createTask(['confirmation_required' => true, 'confirmation_method' => 'photo']);
        $step = $order->factoryOrders()->firstOrFail();
        $this->as($this->operator);
        $this->act($order, 'finished', ['evidence_text' => 'Text cannot replace the required photo'])->assertUnprocessable()->assertJsonValidationErrors('evidence_photo');
        $this->post('/api/factories/updateOrder/'.$order->id, ['factory_id' => $step->factory_id, 'factory_order' => ['status' => 'finished'], 'evidence_photo' => UploadedFile::fake()->createWithContent('bad.svg', '<svg></svg>')], $this->headers())->assertUnprocessable();
        $photo = UploadedFile::fake()->createWithContent('completion.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lT8AAAAASUVORK5CYII='));
        $result = $this->post('/api/factories/updateOrder/'.$order->id, ['factory_id' => $step->factory_id, 'factory_order' => ['status' => 'finished'], 'evidence_photo' => $photo], $this->headers())->assertOk()->assertJsonPath('factory_orders.0.has_evidence_photo', true);
        $this->assertArrayNotHasKey('evidence_photo_path', $result->json('factory_orders.0'));
        Storage::disk('private')->assertExists($step->fresh()->evidence_photo_path);
        $this->get('/api/secure-files/evidence/'.$step->id, $this->headers())->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->as($this->bendOperator);
        $this->getJson('/api/secure-files/evidence/'.$step->id, $this->headers())->assertForbidden();
        $this->as($this->customer);
        $this->getJson('/api/secure-files/evidence/'.$step->id, $this->headers())->assertForbidden();
        $this->as($this->otherEngineer);
        $this->getJson('/api/secure-files/evidence/'.$step->id, $this->headers())->assertForbidden();
        $this->as($this->engineer);
        $this->get('/api/secure-files/evidence/'.$step->id, $this->headers())->assertOk();
        $this->approve($step)->assertOk()->assertJsonPath('order.status', 'completed');
    }

    public function test_reference_files_create_no_fake_steps_and_are_only_shared_with_selected_workshops(): void
    {
        $order = $this->createTask(['selected_files' => array_map(fn ($f) => ['id' => $f->id, 'quantity' => 1], $this->files),
            'reference_file_visibility' => [
                ['file_id' => $this->files['INFO']->id, 'factory_ids' => [$this->factories['DXF']->id]],
                ['file_id' => $this->files['PDF']->id, 'factory_ids' => []],
            ]]);
        $this->assertSame(2, $order->factoryOrders()->count());
        $this->as($this->operator);
        $result = $this->getJson('/api/factories/factory/'.$this->factories['DXF']->id, $this->headers())->assertOk()->assertJsonCount(1, 'orders.0.factory_orders');
        $this->assertSame([$this->files['DXF']->id, $this->files['INFO']->id], array_column($result->json('orders.0.factory_orders.0.files'), 'id'));
        $this->assertArrayNotHasKey('selected_files', $result->json('orders.0'));
        $this->get('/api/secure-files/pmp/'.$this->files['INFO']->id, $this->headers())->assertOk();
        $this->get('/api/secure-files/path/'.$this->files['INFO']->path, $this->headers())->assertOk();
        $this->getJson('/api/secure-files/pmp/'.$this->files['PDF']->id, $this->headers())->assertForbidden();
        $this->getJson('/api/secure-files/pmp/'.$this->files['DLD']->id, $this->headers())->assertForbidden();
        $this->as($this->bendOperator);
        $this->getJson('/api/secure-files/pmp/'.$this->files['INFO']->id, $this->headers())->assertForbidden();
        $this->getJson('/api/secure-files/path/'.$this->files['INFO']->path, $this->headers())->assertForbidden();
        $this->get('/api/secure-files/pmp/'.$this->files['DLD']->id, $this->headers())->assertOk();
    }

    public function test_multiple_reference_files_can_be_shared_with_the_same_workshop(): void
    {
        $order = $this->createTask(['selected_files' => array_map(fn ($f) => ['id' => $f->id, 'quantity' => 1], $this->files),
            'reference_file_visibility' => array_map(fn ($value) => ['file_id' => $this->files[$value]->id, 'factory_ids' => [$this->factories['DXF']->id]], ['INFO', 'PDF'])]);
        $this->assertSame(2, $order->factoryOrders()->count());
        $this->as($this->operator);
        foreach (['INFO', 'PDF'] as $value) $this->get('/api/secure-files/pmp/'.$this->files[$value]->id, $this->headers())->assertOk();
        $this->as($this->bendOperator);
        foreach (['INFO', 'PDF'] as $value) $this->getJson('/api/secure-files/pmp/'.$this->files[$value]->id, $this->headers())->assertForbidden();
    }

    public function test_visibility_cannot_share_production_files_or_reference_files_to_foreign_workshops(): void
    {
        $foreign = $this->in($this->otherCompany, fn () => Factory::where('value', 'DXF')->firstOrFail());
        $this->as($this->engineer);
        foreach ([['file_id' => $this->files['DXF']->id, 'factory_ids' => [$this->factories['DLD']->id]], ['file_id' => $this->files['INFO']->id, 'factory_ids' => [$foreign->id]]] as $share) {
            $this->postJson('/api/engineers/engineer', $this->payload(['selected_files' => array_map(fn ($f) => ['id' => $f->id, 'quantity' => 1], $this->files), 'reference_file_visibility' => [$share]]), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('reference_file_visibility');
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_completion_of_one_workshop_does_not_complete_another_workshop_or_company(): void
    {
        $order = $this->createTask(['confirmation_required' => true, 'confirmation_method' => 'text', 'selected_files' => [['id' => $this->files['DXF']->id, 'quantity' => 1], ['id' => $this->files['DLD']->id, 'quantity' => 1]]]);
        $steps = $order->factoryOrders()->orderBy('id')->get();
        $this->as($this->operator); $this->act($order, 'finished', ['evidence_text' => 'Laser completed'])->assertOk();
        $this->as($this->engineer); $this->approve($steps[0])->assertOk()->assertJsonPath('order.status', 'pending');
        $this->as($this->bendOperator);
        $this->putJson('/api/factories/updateOrder/'.$order->id, ['factory_id' => $steps[0]->factory_id, 'factory_order' => ['status' => 'finished', 'evidence_text' => 'Spoofed']], $this->headers())->assertForbidden();
        $this->act($order, 'finished', ['evidence_text' => 'Bending completed'])->assertOk();
        $otherMembership = CompanyMembership::create(['user_id' => $this->engineer->id, 'company_id' => $this->otherCompany->id, 'role_id' => $this->engineer->getRawOriginal('role_id')]);
        $otherMembership->permissions()->sync(Permission::where('slug', 'orders.view')->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        $this->as($this->engineer);
        $this->postJson('/api/engineers/factory-orders/'.$steps[1]->id.'/confirm', [], ['X-Company-ID' => $this->otherCompany->id])->assertNotFound();
        $this->getJson('/api/engineers/engineer', ['X-Company-ID' => $this->otherCompany->id])->assertOk()->assertJsonCount(0, 'orders');
        $this->approve($steps[1])->assertOk()->assertJsonPath('order.status', 'completed');
    }

    public function test_operator_cannot_disable_required_evidence_or_forge_confirmation_fields(): void
    {
        $order = $this->createTask(['confirmation_required' => true, 'confirmation_method' => 'text']);
        $this->as($this->operator);
        foreach (['confirmation_required' => false, 'confirmation_method' => 'photo', 'engineer_confirmation_at' => '2026-10-10', 'completed_at' => '2026-10-10', 'admin_confirmation_date' => '2026-10-10'] as $field => $value) {
            $this->act($order, 'finished', [$field => $value, 'evidence_text' => 'Complete'])->assertUnprocessable()->assertJsonValidationErrors('factory_order.'.$field);
        }
        $this->assertSame('pending', $order->factoryOrders()->first()->status);
    }

    public function test_confirmation_and_files_cannot_be_changed_after_the_operator_accepts_the_task(): void
    {
        $order = $this->createTask(['confirmation_required' => true, 'confirmation_method' => 'text']);
        $this->as($this->operator); $this->act($order, 'confirmed')->assertOk();
        $this->as($this->engineer);
        $this->putJson('/api/engineers/engineer/'.$order->id, $this->payload(['confirmation_required' => false]), $this->headers())->assertUnprocessable();
        $this->assertTrue($order->fresh()->confirmation_required);
    }

    public function test_migration_is_retryable_and_old_finished_tasks_no_longer_need_an_admin(): void
    {
        $order = $this->createTask();
        $step = $order->factoryOrders()->firstOrFail();
        DB::table('factory_orders')->where('id', $step->id)->update(['status' => 'finished', 'operator_finish_date' => '2026-10-08']);
        $migration = require database_path('migrations/2026_10_09_150000_add_engineer_task_confirmation.php');
        $migration->up(); $migration->up();
        $this->assertNotNull($step->fresh()->completed_at);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNull($step->fresh()->admin_confirmation_date);
    }

    private function in(Company $company, callable $callback): mixed { return app(CompanyContext::class)->run($company, $callback); }
    private function headers(): array { return ['X-Company-ID' => (string) $this->company->id, 'Accept' => 'application/json']; }
    private function as(User $user): void { app(CompanyContext::class)->set(null); $user->unsetRelation('role')->unsetRelation('factory'); Sanctum::actingAs($user); }
    private function account(string $name, string $role, ?int $factoryId = null): User
    {
        return $this->in($this->company, function () use ($name, $role, $factoryId) {
            $user = User::create(['name' => $name, 'email' => $name.'@example.invalid', 'password' => 'test-password', 'role_id' => Role::where('name', $role)->value('id'), 'factory_id' => $factoryId]);
            $membership = CompanyMembership::where('company_id', $this->company->id)->where('user_id', $user->id)->firstOrFail();
            $membership->permissions()->sync(Permission::whereIn('slug', ['orders.view', 'orders.create', 'orders.update', 'factory.view', 'factory.download', 'factory.order_update', 'pmp_files.view'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
            return $user;
        });
    }
    private function payload(array $extra = []): array
    {
        return array_replace(['user_id' => $this->customer->id, 'description' => 'A private task', 'name' => '990.01', 'finish_date' => '2026-10-20', 'pmp_id' => $this->pmp->id, 'link_existing_files' => true, 'selected_files' => [['id' => $this->files['DXF']->id, 'quantity' => 2]]], $extra);
    }
    private function createTask(array $extra = []): Order
    {
        $this->as($this->engineer);
        $response = $this->postJson('/api/engineers/engineer', $this->payload($extra), $this->headers())->assertCreated();
        return Order::findOrFail($response->json('order.id'));
    }
    private function act(Order $order, string $status, array $extra = [])
    {
        $factoryId = $this->in($this->company, fn () => auth()->user()->factory_id);
        return $this->putJson('/api/factories/updateOrder/'.$order->id, ['factory_id' => $factoryId, 'factory_order' => ['status' => $status] + $extra], $this->headers());
    }
    private function approve(FactoryOrder $step) { return $this->postJson('/api/engineers/factory-orders/'.$step->id.'/confirm', [], $this->headers()); }
}
