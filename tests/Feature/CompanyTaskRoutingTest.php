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

class CompanyTaskRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otherCompany;
    private User $engineer;
    private User $otherEngineer;
    private User $customer;
    private User $admin;
    private User $operator;
    private User $secondOperator;
    private User $manager;
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
        $this->secondOperator = $this->account('laser-second', 'laser', $this->factories['DXF']->id);
        $this->manager = $this->account('manager', 'manager');
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


    public function test_workload_counts_all_company_jobs_without_disclosing_other_engineers_tasks(): void
    {
        $own = $this->createTask(['factory_operators' => [['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->operator->id]], 'finish_date' => '2026-01-01']);
        $other = $this->createTask(['factory_operators' => [['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->operator->id]]]);
        $other->update(['creator_id' => $this->otherEngineer->id, 'description' => 'Other engineer secret']);
        $this->createTask();
        $review = $this->createTask(['confirmation_required' => true, 'confirmation_method' => 'text', 'factory_operators' => [['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->operator->id]]]);
        $this->as($this->operator); $this->act($review, 'finished', ['evidence_text' => 'Finished'])->assertOk();
        $this->in($this->otherCompany, function () {
            $client = User::create(['name'=>'Foreign client','email'=>'foreign-client@example.invalid','password'=>'password','role_id'=>Role::where('name','authenticatedUser')->value('id')]);
            $foreign = Order::create(['user_id'=>$client->id,'creator_id'=>$client->id,'name' => 'Foreign secret', 'description' => 'Foreign', 'status' => 'pending']);
            FactoryOrder::create(['order_id' => $foreign->id, 'factory_id' => Factory::where('value', 'DXF')->value('id'), 'status' => 'pending']);
        });
        $this->as($this->engineer);
        $response = $this->getJson('/api/task-workload', $this->headers())->assertOk();
        $factory = collect($response->json('factories'))->firstWhere('id', $this->factories['DXF']->id);
        $operator = collect($factory['operators'])->firstWhere('id', $this->operator->id);
        $this->assertSame(2, $operator['active_tasks']); $this->assertSame(1, $operator['overdue_tasks']);
        $this->assertSame(1, $operator['awaiting_review']); $this->assertSame(1, $factory['unassigned_tasks']);
        $this->assertSame(0, collect($factory['operators'])->firstWhere('id', $this->secondOperator->id)['active_tasks']);
        $this->assertSame($this->company->id, $response->json('company_id'));
        foreach (['Other engineer secret', 'Foreign secret', '@example.invalid', 'evidence_text', 'order_id'] as $secret) $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertNotContains('INFO', array_column($response->json('factories'), 'value'));
        $this->getJson('/api/engineers/engineer', $this->headers())->assertOk()->assertJsonCount(3, 'orders');
        foreach ([$this->customer, $this->operator] as $user) { $this->as($user); $this->getJson('/api/task-workload', $this->headers())->assertForbidden(); }
        $membership = $this->engineer->memberships()->where('company_id', $this->company->id)->firstOrFail();
        $membership->permissions()->detach(Permission::where('slug','orders.view')->value('id'));
        $this->as($this->engineer); $this->getJson('/api/task-workload', $this->headers())->assertOk();
        $membership->permissions()->detach(Permission::where('slug','orders.create')->value('id'));
        $this->getJson('/api/task-workload', $this->headers())->assertForbidden();
    }

    public function test_creator_can_replace_an_accepted_operator_and_assign_an_unassigned_job_later(): void
    {
        $order = $this->createTask(['factory_operators' => [['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->operator->id]], 'confirmation_required' => true, 'confirmation_method' => 'text']);
        $step = $order->factoryOrders()->firstOrFail();
        $this->as($this->operator); $this->act($order, 'confirmed')->assertOk();
        $this->as($this->engineer);
        $result = $this->assign($order, $step, $this->secondOperator->id)->assertOk()->assertJsonPath('order.factory_orders.0.status', 'confirmed')->assertJsonPath('order.factory_orders.0.operator_id', $this->secondOperator->id);
        $this->assertArrayNotHasKey('depends_on', $result->json('order.factory_orders.0'));
        $this->assertTrue($step->fresh()->confirmation_required);
        $this->assertSame($this->operator->id, $order->logs()->where('action', 'factory_order.operator_changed')->firstOrFail()->meta['from_operator_id']);
        $this->assign($order, $step, $this->secondOperator->id)->assertOk();
        $this->assertSame(1, $order->logs()->where('action', 'factory_order.operator_changed')->count());
        $this->as($this->operator);
        $this->getJson('/api/orders/'.$order->id, $this->headers())->assertForbidden();
        $this->getJson('/api/secure-files/pmp/'.$this->files['DXF']->id, $this->headers())->assertForbidden();
        $this->act($order, 'finished', ['evidence_text' => 'Stale action'])->assertForbidden();
        $this->as($this->secondOperator); $this->get('/api/secure-files/pmp/'.$this->files['DXF']->id, $this->headers())->assertOk();
        $this->act($order, 'finished', ['evidence_text' => 'Reassigned cutting completed'])->assertOk();
        $this->as($this->engineer); $this->assign($order, $step, null)->assertUnprocessable();
        $this->approve($step)->assertOk()->assertJsonPath('order.status', 'completed');
        $new = $this->createTask(); $newStep = $new->factoryOrders()->firstOrFail();
        $this->assign($new, $newStep, $this->secondOperator->id)->assertOk();
        $this->assign($new, $newStep, null)->assertOk()->assertJsonPath('order.factory_orders.0.operator_id', null);
        $this->assign($new, $newStep, $this->operator->id)->assertOk();
        $this->putJson('/api/engineers/engineer/'.$new->id, $this->payload(), $this->headers())->assertUnprocessable();
        $this->assertSame($this->operator->id, $newStep->fresh()->operator_id);
    }

    public function test_only_creator_or_management_can_route_and_all_company_workshop_memberships_are_supported(): void
    {
        $order = $this->createTask(); $step = $order->factoryOrders()->firstOrFail();
        foreach ([$this->otherEngineer, $this->operator, $this->customer] as $user) {
            $this->as($user); $this->assign($order, $step, $this->operator->id)->assertForbidden();
            $this->add($order)->assertForbidden();
        }
        $this->as($this->engineer);
        $other = $this->createTask(); $this->assign($order, $other->factoryOrders()->first(), $this->operator->id)->assertNotFound();
        foreach ([$this->bendOperator, $this->customer] as $invalid) $this->assign($order, $step, $invalid->id)->assertUnprocessable()->assertJsonValidationErrors('operator_id');
        $secondary = $this->account('secondary', 'engineer');
        $membership = $secondary->memberships()->where('company_id', $this->company->id)->firstOrFail();
        MembershipAssignments::sync($membership, [['role_id' => Role::where('name', 'engineer')->value('id'), 'factory_id' => null], ['role_id' => Role::where('name', 'laser')->value('id'), 'factory_id' => $this->factories['DXF']->id]]);
        foreach ([$this->admin, $this->manager] as $manager) {
            $this->as($manager);
            $this->putJson('/api/admin/factory-orders/'.$step->id.'/operator', ['operator_id' => $secondary->id], $this->headers())->assertOk();
            $this->assign($order, $step, $this->operator->id)->assertOk();
        }
        $membership->update(['is_active' => false]); app(CompanyContext::class)->forgetMembership($secondary->id);
        $this->as($this->engineer); $this->assign($order, $step, $secondary->id)->assertUnprocessable();
        $foreign = $this->in($this->otherCompany, fn () => User::create(['name' => 'Foreign operator', 'email' => 'foreign@example.invalid', 'password' => 'password', 'role_id' => Role::where('name', 'laser')->value('id'), 'factory_id' => Factory::where('value','DXF')->value('id')]));
        $this->assign($order, $step, $foreign->id)->assertUnprocessable();
    }

    public function test_same_file_can_pass_from_laser_to_bend_with_private_access_and_required_creator_approval(): void
    {
        $order = $this->createTask(['confirmation_required' => true, 'confirmation_method' => 'text', 'selected_files' => array_map(fn ($key) => ['id' => $this->files[$key]->id, 'quantity' => 2], ['DXF','INFO','PDF'])]);
        $laser = $order->factoryOrders()->firstOrFail();
        $this->as($this->bendOperator); $this->getJson('/api/secure-files/pmp/'.$this->files['DXF']->id, $this->headers())->assertForbidden();
        $this->as($this->engineer);
        $response = $this->add($order, ['depends_on_id' => $laser->id, 'files' => [['id' => $this->files['DXF']->id, 'quantity' => 2], ['id' => $this->files['INFO']->id, 'quantity' => 1]]])->assertCreated();
        $bend = $order->factoryOrders()->where('factory_id', $this->factories['DLD']->id)->firstOrFail();
        $this->assertTrue($bend->is_blocked); $this->assertTrue($bend->confirmation_required);
        $this->assertArrayNotHasKey('depends_on', collect($response->json('order.factory_orders'))->firstWhere('id', $bend->id));
        $this->assertSame($this->factories['DXF']->id, $this->files['DXF']->fresh()->factory_id);
        $this->as($this->bendOperator);
        $board = $this->getJson('/api/factories/factory/'.$bend->factory_id, $this->headers())->assertOk()->assertJsonPath('orders.0.factory_orders.0.is_blocked', true)->assertJsonCount(1, 'orders.0.factory_orders');
        $this->assertArrayNotHasKey('depends_on', $board->json('orders.0.factory_orders.0'));
        foreach (['DXF','INFO'] as $key) $this->get('/api/secure-files/pmp/'.$this->files[$key]->id, $this->headers())->assertOk();
        $this->getJson('/api/secure-files/pmp/'.$this->files['PDF']->id, $this->headers())->assertForbidden();
        foreach (['confirmed','finished'] as $status) $this->act($order, $status, ['evidence_text' => 'Premature'])->assertUnprocessable()->assertJsonValidationErrors('factory_order.status');
        $this->as($this->operator); $this->act($order, 'finished', ['evidence_text' => 'Laser cut complete'])->assertOk();
        $this->as($this->bendOperator); $this->act($order, 'confirmed')->assertUnprocessable();
        $this->as($this->engineer); $this->approve($laser)->assertOk()->assertJsonPath('order.status', 'pending');
        $this->assertFalse($bend->fresh()->is_blocked);
        $this->as($this->bendOperator); $this->act($order, 'confirmed')->assertOk(); $this->act($order, 'finished', ['evidence_text' => 'Bend complete'])->assertOk();
        $this->as($this->engineer); $this->approve($bend)->assertOk()->assertJsonPath('order.status', 'completed');
        $this->assertSame('Laser cut complete', $laser->fresh()->evidence_text); $this->assertNotNull($laser->fresh()->engineer_confirmation_at);
    }

    public function test_adding_work_after_completion_reopens_task_without_changing_previous_evidence(): void
    {
        $order = $this->createTask(['confirmation_required' => true, 'confirmation_method' => 'text']); $laser = $order->factoryOrders()->firstOrFail();
        $this->as($this->operator); $this->act($order, 'finished', ['evidence_text' => 'Original completed work'])->assertOk();
        $this->as($this->engineer); $this->approve($laser)->assertOk(); $stamp = $laser->fresh()->completed_at;
        $this->assertNotNull($order->fresh()->completed_at);
        $this->add($order, ['depends_on_id' => $laser->id])->assertCreated()->assertJsonPath('order.status', 'pending')->assertJsonPath('order.completed_at', null);
        $this->assertSame($stamp, $laser->fresh()->completed_at); $this->assertSame('Original completed work', $laser->fresh()->evidence_text);
        $this->assertTrue($order->logs()->where('action','factory_order.work_added')->first()->meta['reopened_task']);
        $this->assign($order, $laser, $this->secondOperator->id)->assertUnprocessable();
    }

    public function test_routing_rejects_foreign_files_unused_files_duplicate_links_reference_only_work_and_cycles(): void
    {
        $order = $this->createTask(['selected_files' => [['id'=>$this->files['DXF']->id,'quantity'=>1], ['id'=>$this->files['INFO']->id,'quantity'=>1]]]); $laser = $order->factoryOrders()->firstOrFail();
        $this->as($this->engineer);
        $this->add($order, ['files'=>[['id'=>$this->files['INFO']->id,'quantity'=>1]]])->assertUnprocessable();
        $this->add($order, ['files'=>[['id'=>$this->files['DLD']->id,'quantity'=>1]]])->assertUnprocessable();
        $this->add($order, ['factory_id'=>$this->factories['INFO']->id])->assertUnprocessable();
        $foreignFile = $this->in($this->otherCompany, function () { $pmp=Pmp::create(['group'=>'991','group_name'=>'Foreign QA','admin_confirmation'=>false]); $remote=RemoteNumber::create(['pmp_id'=>$pmp->id,'remote_number'=>'01','remote_number_name'=>'Foreign QA']); return PmpFiles::create(['pmp_id'=>$pmp->id,'remote_number_id'=>$remote->id,'factory_id'=>Factory::where('value','DXF')->value('id'),'path'=>'foreign.txt','original_name'=>'foreign.txt']); });
        $this->add($order, ['files'=>[['id'=>$foreignFile->id,'quantity'=>1]]])->assertUnprocessable();
        $other = $this->createTask(); $otherStep = $other->factoryOrders()->first();
        $this->add($order, ['depends_on_id'=>$otherStep->id])->assertUnprocessable();
        $this->add($order, ['depends_on_id'=>$laser->id, 'operator_id'=>null])->assertCreated();
        $bend = $order->factoryOrders()->where('factory_id',$this->factories['DLD']->id)->firstOrFail();
        $this->add($order)->assertUnprocessable();
        $this->add($order, ['factory_id'=>$laser->factory_id,'depends_on_id'=>$bend->id,'files'=>[['id'=>$this->files['INFO']->id,'quantity'=>1],['id'=>$this->files['DXF']->id,'quantity'=>1]]])->assertUnprocessable();
        $extra = $this->in($this->company, function () use ($order) {
            $file = PmpFiles::create(['pmp_id'=>$this->pmp->id,'remote_number_id'=>$this->files['DXF']->remote_number_id,'factory_id'=>$this->factories['DXF']->id,'path'=>'extra.txt','original_name'=>'extra.txt']);
            $order->selectedFiles()->create(['pmp_file_id'=>$file->id,'quantity'=>3]); return $file;
        });
        $this->add($order,['factory_id'=>$laser->factory_id,'depends_on_id'=>$bend->id,'files'=>[['id'=>$extra->id,'quantity'=>3]],'operator_id'=>null])->assertUnprocessable()->assertJsonValidationErrors('depends_on_id');
        $this->add($order,['files'=>[['id'=>$extra->id,'quantity'=>3]],'operator_id'=>null])->assertCreated();
        $this->assertSame($laser->id, $bend->fresh()->depends_on_id);
        $this->assertSame(3, $bend->files()->where('pmp_files.id',$extra->id)->firstOrFail()->pivot->quantity);
        $this->putJson('/api/engineers/engineer/'.$order->id,$this->payload(),$this->headers())->assertUnprocessable();
        $this->assertSame(2,$order->factoryOrders()->count());
        $migration=require database_path('migrations/2026_10_09_180000_add_task_workshop_dependencies.php'); $migration->up(); $migration->up();
        $this->assertSame($laser->id,$bend->fresh()->depends_on_id);
    }

    public function test_started_workshop_cannot_receive_files_and_canceled_tasks_cannot_be_routed(): void
    {
        $order = $this->createTask(['selected_files'=>[['id'=>$this->files['DXF']->id,'quantity'=>1],['id'=>$this->files['DLD']->id,'quantity'=>1]]]);
        $this->as($this->operator); $this->act($order,'confirmed')->assertOk();
        $this->as($this->engineer);
        $this->add($order,['factory_id'=>$this->factories['DXF']->id,'files'=>[['id'=>$this->files['DLD']->id,'quantity'=>1]]])->assertUnprocessable();
        $order->update(['status'=>'canceled']);
        $this->assign($order,$order->factoryOrders()->first(),$this->secondOperator->id)->assertUnprocessable(); $this->add($order)->assertUnprocessable();
    }

    private function assign(Order $order, FactoryOrder $step, ?int $operator) { return $this->putJson('/api/tasks/'.$order->id.'/workshops/'.$step->id.'/operator',['operator_id'=>$operator],$this->headers()); }
    private function add(Order $order,array $extra=[]) { return $this->postJson('/api/tasks/'.$order->id.'/workshops',array_replace(['factory_id'=>$this->factories['DLD']->id,'operator_id'=>$this->bendOperator->id,'files'=>[['id'=>$this->files['DXF']->id,'quantity'=>2]]],$extra),$this->headers()); }
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
