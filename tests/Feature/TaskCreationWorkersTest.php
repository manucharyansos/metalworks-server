<?php

namespace Tests\Feature;

use App\Models\{Company, CompanyMembership, Factory, FactoryOrder, Order, Permission, Pmp, PmpFiles, RemoteNumber, Role, TaskChangeRequest, TaskNotification, User};
use App\Support\CompanyContext;
use Database\Seeders\{FactorySeeder, FactoryOrderStatusSeeder, RoleTableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail, Schema, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Creating a task and choosing who does it: whole subgroup or chosen files,
 * one operator per workshop, validated against the workshops the task uses.
 */
class TaskCreationWorkersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $engineer;
    private User $otherEngineer;
    private User $customer;
    private User $admin;
    private User $operator;
    private User $secondOperator;
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
        $this->in($this->company, function () { (new FactorySeeder)->run(); (new FactoryOrderStatusSeeder)->run(); });
        $this->factories = $this->in($this->company, fn () => Factory::get()->keyBy('value')->all());
        $this->engineer = $this->account('creator', 'engineer');
        $this->otherEngineer = $this->account('another', 'engineer');
        $this->admin = $this->account('admin', 'admin');
        $this->customer = $this->account('client', 'authenticatedUser');
        $this->operator = $this->account('laser', 'laser', $this->factories['DXF']->id);
        $this->secondOperator = $this->account('laser-second', 'laser', $this->factories['DXF']->id);
        $this->bendOperator = $this->account('bend', 'bend', $this->factories['DLD']->id);
        $this->pmp = $this->in($this->company, fn () => Pmp::create(['group' => '990', 'group_name' => 'Task testing', 'admin_confirmation' => false]));
        Storage::fake('private'); Mail::fake();
        $this->files = $this->in($this->company, function () {
            $remote = RemoteNumber::create(['pmp_id' => $this->pmp->id, 'remote_number' => '01', 'remote_number_name' => 'QA']);
            $files = [];
            foreach (['DXF', 'DLD'] as $value) {
                $files[$value] = PmpFiles::create(['pmp_id' => $this->pmp->id, 'remote_number_id' => $remote->id, 'factory_id' => $this->factories[$value]->id, 'path' => 'companies/'.$this->company->id.'/qa/'.$value.'.txt', 'original_name' => $value.'.txt']);
                Storage::disk('private')->put($files[$value]->path, $value.' private data');
            }
            return $files;
        });
    }

    // ── Choosing workers while creating ─────────────────────────────────────

    public function test_workers_can_be_chosen_for_the_whole_subgroup_and_for_chosen_files(): void
    {
        $everyone = [
            ['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->secondOperator->id],
            ['factory_id' => $this->factories['DLD']->id, 'user_id' => $this->bendOperator->id],
        ];
        $whole = $this->createTask(['link_existing_files' => false, 'selected_files' => [], 'remote_number_id' => $this->files['DXF']->remote_number_id, 'factory_operators' => $everyone]);
        $this->assertEqualsCanonicalizing([$this->secondOperator->id, $this->bendOperator->id], $whole->factoryOrders()->pluck('operator_id')->all());
        $this->assertSame(2, $whole->selectedFiles()->count());

        $chosen = $this->createTask(['selected_files' => [['id' => $this->files['DLD']->id, 'quantity' => 3]], 'factory_operators' => [['factory_id' => $this->factories['DLD']->id, 'user_id' => $this->bendOperator->id]]]);
        $this->assertSame([$this->factories['DLD']->id], $chosen->factoryOrders()->pluck('factory_id')->all());
        $this->assertSame($this->bendOperator->id, $chosen->factoryOrders()->firstOrFail()->operator_id);

        // Each chosen worker hears about the job; nobody is told about a job they were not given.
        $types = fn (User $user) => TaskNotification::withoutGlobalScopes()->where('user_id', $user->id)->pluck('type')->all();
        $this->assertSame(['step.assigned'], $types($this->secondOperator));
        $this->assertSame(['step.assigned', 'step.assigned'], $types($this->bendOperator));
        $this->assertSame([], $types($this->operator));
    }

    public function test_a_worker_for_a_workshop_that_gets_no_job_is_rejected_instead_of_silently_dropped(): void
    {
        $this->as($this->engineer);
        $before = Order::withoutGlobalScopes()->count();
        $this->postJson('/api/engineers/engineer', $this->payload(['factory_operators' => [['factory_id' => $this->factories['DLD']->id, 'user_id' => $this->bendOperator->id]]]), $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('factory_operators');
        $this->assertSame($before, Order::withoutGlobalScopes()->count(), 'nothing is created from a half-valid request');
    }

    public function test_one_worker_per_workshop_and_only_people_of_that_workshop(): void
    {
        $this->as($this->engineer);
        $twice = [['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->operator->id], ['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->secondOperator->id]];
        $this->postJson('/api/engineers/engineer', $this->payload(['factory_operators' => $twice]), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('factory_operators');
        $foreign = [['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->bendOperator->id]];
        $this->postJson('/api/engineers/engineer', $this->payload(['factory_operators' => $foreign]), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('factory_operators');
        $this->postJson('/api/engineers/engineer', $this->payload(['factory_operators' => [['factory_id' => $this->factories['DXF']->id, 'user_id' => 999999]]]), $this->headers())->assertUnprocessable();
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_workers_can_be_changed_after_creation_in_any_unfinished_state_and_workload_follows(): void
    {
        $order = $this->createTask($this->laser());
        $step = $order->factoryOrders()->firstOrFail();
        $this->as($this->operator); $this->act($order, 'confirmed')->assertOk();
        $this->as($this->engineer);
        $this->assign($order, $step, $this->secondOperator->id)->assertOk()->assertJsonPath('order.factory_orders.0.operator_id', $this->secondOperator->id);
        $load = collect($this->getJson('/api/task-workload', $this->headers())->assertOk()->json('factories'))->firstWhere('id', $this->factories['DXF']->id);
        $counts = collect($load['operators'])->pluck('active_tasks', 'id');
        $this->assertSame(0, $counts[$this->operator->id]);
        $this->assertSame(1, $counts[$this->secondOperator->id]);
        $this->assign($order, $step, null)->assertOk()->assertJsonPath('order.factory_orders.0.operator_id', null);
        $this->assertSame(1, collect($this->getJson('/api/task-workload', $this->headers())->json('factories'))->firstWhere('id', $this->factories['DXF']->id)['unassigned_tasks']);
    }

    // ── Form data and editing ───────────────────────────────────────────────

    public function test_form_data_offers_only_customer_accounts_never_staff_emails(): void
    {
        $this->as($this->engineer);
        $order = $this->createTask($this->laser());
        $this->as($this->engineer);
        foreach (['/api/engineers/engineer/create', '/api/engineers/engineer/'.$order->id.'/edit'] as $url) {
            $users = $this->getJson($url, $this->headers())->assertOk()->json('users');
            $this->assertSame(['client@example.invalid'], array_column($users, 'email'), $url);
        }
    }

    public function test_reselecting_files_keeps_workers_and_workshop_settings_of_a_task_nobody_started(): void
    {
        $both = [['id' => $this->files['DXF']->id, 'quantity' => 2], ['id' => $this->files['DLD']->id, 'quantity' => 2]];
        $order = $this->createTask([
            'selected_files' => $both,
            'factory_operators' => [['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->secondOperator->id], ['factory_id' => $this->factories['DLD']->id, 'user_id' => $this->bendOperator->id]],
            'workshop_settings' => [
                ['factory_id' => $this->factories['DXF']->id, 'confirmation_required' => false, 'confirmation_method' => null, 'deadline' => null, 'priority' => 'normal'],
                ['factory_id' => $this->factories['DLD']->id, 'confirmation_required' => true, 'confirmation_method' => 'text', 'deadline' => '2098-05-01', 'priority' => 'urgent'],
            ],
        ]);
        $snapshot = fn () => $order->factoryOrders()->orderBy('factory_id')->get()->map(fn ($s) => [$s->factory_id, $s->operator_id, (bool) $s->confirmation_required, $s->confirmation_method, $s->priority, substr((string) $s->getRawOriginal('deadline'), 0, 10)])->all();
        $before = $snapshot();
        $this->assertContains([$this->factories['DLD']->id, $this->bendOperator->id, true, 'text', 'urgent', '2098-05-01'], $before);

        $this->as($this->engineer);
        $this->putJson('/api/engineers/engineer/'.$order->id, $this->payload(['description' => 'Corrected description', 'selected_files' => [['id' => $this->files['DXF']->id, 'quantity' => 5], ['id' => $this->files['DLD']->id, 'quantity' => 7]]]), $this->headers())->assertOk();
        $this->assertSame($before, $snapshot(), 'workers, priority, deadline and proof survive editing');
        $this->assertSame('Corrected description', $order->fresh()->description);
        $this->assertEqualsCanonicalizing([5, 7], $order->selectedFiles()->pluck('quantity')->all());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function laser(array $extra = []): array
    {
        return array_replace(['factory_operators' => [['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->operator->id]]], $extra);
    }
    private function notes(User $user) { $this->as($user); return $this->getJson('/api/notifications', $this->headers())->assertOk(); }
    private function lastRequest(Order $order): TaskChangeRequest { return TaskChangeRequest::withoutGlobalScopes()->where('order_id', $order->id)->orderByDesc('id')->firstOrFail(); }
    private function decide(TaskChangeRequest $request, string $decision, array $extra = []) { return $this->postJson('/api/engineers/task-requests/'.$request->id.'/decision', ['decision' => $decision] + $extra, $this->headers()); }
    private function withdraw(TaskChangeRequest $request) { return $this->postJson('/api/task-requests/'.$request->id.'/withdraw', [], $this->headers()); }
    private function return(FactoryOrder $step, array $body) { return $this->postJson('/api/engineers/factory-orders/'.$step->id.'/return', $body, $this->headers()); }
    private function assign(Order $order, FactoryOrder $step, ?int $operator) { return $this->putJson('/api/tasks/'.$order->id.'/workshops/'.$step->id.'/operator', ['operator_id' => $operator], $this->headers()); }
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
