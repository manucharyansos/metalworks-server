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


    public function test_description_can_be_missing_empty_or_null_without_affecting_workshop_files(): void
    {
        foreach (['missing', '', null, '   '] as $description) {
            $this->as($this->engineer);
            $data = $this->payload();
            if ($description === 'missing') unset($data['description']);
            else $data['description'] = $description;
            $response = $this->postJson('/api/engineers/engineer', $data, $this->headers())->assertCreated();
            $order = Order::findOrFail($response->json('order.id'));
            $this->assertSame('', $order->description);
            $this->assertSame(1, $order->factoryOrders()->count());
            $this->assertSame(2, $order->selectedFiles()->firstOrFail()->quantity);
        }
        $before = Order::count();
        $this->postJson('/api/engineers/engineer', $this->payload(['description' => ['invalid']]), $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertSame($before, Order::count());
    }

    public function test_descriptionless_tasks_remain_editable_and_omitting_description_preserves_existing_text(): void
    {
        $order = $this->createTask(['description' => 'Keep this text']);
        $data = $this->payload();
        unset($data['description']);
        $this->putJson('/api/engineers/engineer/'.$order->id, $data, $this->headers())->assertOk();
        $this->assertSame('Keep this text', $order->fresh()->description);
        $this->putJson('/api/engineers/engineer/'.$order->id, $this->payload(['description' => null]), $this->headers())->assertOk();
        $this->assertSame('', $order->fresh()->description);
        foreach ([[$this->manager, '/api/orders/order/'], [$this->admin, '/api/admin/order/']] as [$actor, $route]) {
            $this->as($actor);
            $this->putJson($route.$order->id, ['name' => $order->name, 'description' => '', 'factories' => [['id' => $this->factories['DXF']->id]]], $this->headers())->assertOk();
            $this->assertSame('', $order->fresh()->description);
        }
        $this->as($this->manager);
        $created = $this->postJson('/api/orders/order', ['name' => 'Management task', 'user_id' => $this->customer->id, 'factories' => [['id' => $this->factories['DXF']->id]]], $this->headers())->assertCreated();
        $this->assertSame('', Order::findOrFail($created->json('id'))->description);
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
        $this->as($this->engineer); $this->approve($laser)->assertOk(); $stamp = $laser->fresh()->getRawOriginal('completed_at');
        $this->assertNotNull($order->fresh()->completed_at);
        $this->add($order, ['depends_on_id' => $laser->id])->assertCreated()->assertJsonPath('order.status', 'pending')->assertJsonPath('order.completed_at', null);
        $this->assertSame($stamp, $laser->fresh()->getRawOriginal('completed_at')); $this->assertSame('Original completed work', $laser->fresh()->evidence_text);
        $this->assertTrue($order->logs()->where('action','factory_order.work_added')->first()->meta['reopened_task']);
        $this->assign($order, $laser, $this->secondOperator->id)->assertUnprocessable();
    }

    public function test_added_workshop_inherits_both_required_evidence_methods(): void
    {
        $order = $this->createTask(['confirmation_required' => true, 'confirmation_method' => 'photo_text']);
        $laser = $order->factoryOrders()->firstOrFail();
        $this->add($order, ['depends_on_id' => $laser->id])->assertCreated();
        $bend = $order->factoryOrders()->where('factory_id', $this->factories['DLD']->id)->firstOrFail();
        $this->assertTrue($bend->confirmation_required);
        $this->assertSame('photo_text', $bend->confirmation_method);
        $this->assertTrue($bend->is_blocked);
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
    public function test_creation_builds_a_sequential_route_with_workshop_deadlines_and_private_files(): void
    {
        $plan = $this->routePlan('sequential');
        $order = $this->createTask($this->routePayload($plan));
        $steps = $order->factoryOrders()->get()->keyBy('factory_id');
        $laser = $steps[$this->factories['DXF']->id]; $bend = $steps[$this->factories['DLD']->id]; $powder = $steps[$this->factories['POWDER']->id];
        $this->assertFalse($laser->is_blocked); $this->assertTrue($bend->is_blocked); $this->assertTrue($powder->is_blocked);
        $this->assertSame([$laser->id], $bend->dependencyIds()); $this->assertSame([$bend->id], $powder->dependencyIds());
        $this->assertSame($plan['stages'][1]['deadline'], $bend->deadline);
        $this->as($this->bendOperator);
        foreach (['accepted', 'confirmed', 'finished'] as $status) $this->act($order, $status)->assertUnprocessable();
        $board = $this->getJson('/api/factories/factory/'.$bend->factory_id, $this->headers())->assertOk();
        $this->assertCount(1, $board->json('orders.0.factory_orders'));
        $this->assertSame([$this->factories['DXF']->name], $board->json('orders.0.factory_orders.0.waiting_for'));
        $this->assertArrayNotHasKey('depends_on', $board->json('orders.0.factory_orders.0'));
        foreach (['operator@example', 'Laser private data'] as $secret) $this->assertStringNotContainsString($secret, $board->getContent());
        $this->as($this->operator); $this->act($order, 'finished')->assertOk();
        $this->assertFalse($bend->fresh()->is_blocked); $this->assertTrue($powder->fresh()->is_blocked);
        $this->assertSame(1, \App\Models\TaskNotification::where('factory_order_id', $bend->id)->where('type', 'step.ready')->where('user_id', $this->bendOperator->id)->count());
        $this->as($this->bendOperator); $this->act($order, 'finished')->assertOk();
        $this->assertFalse($powder->fresh()->is_blocked); $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_mixed_join_waits_for_all_predecessors_and_engineer_approval_then_notifies_once(): void
    {
        $powderOperator = $this->account('powder', 'operator', $this->factories['POWDER']->id);
        $plan = $this->routePlan('custom');
        $plan['stages'][2]['predecessor_factory_ids'] = [$this->factories['DXF']->id, $this->factories['DLD']->id];
        $data = $this->routePayload($plan);
        $data['factory_operators'][] = ['factory_id' => $this->factories['POWDER']->id, 'user_id' => $powderOperator->id];
        $data['workshop_settings'] = [['factory_id' => $this->factories['DLD']->id, 'confirmation_required' => true, 'confirmation_method' => 'text', 'priority' => 'normal']];
        $order = $this->createTask($data); $steps = $order->factoryOrders()->get()->keyBy('factory_id');
        $bend = $steps[$this->factories['DLD']->id]; $powder = $steps[$this->factories['POWDER']->id];
        $this->as($this->operator); $this->act($order, 'finished')->assertOk();
        $this->as($this->bendOperator); $this->act($order, 'finished', ['evidence_text' => 'Bending checked'])->assertOk();
        $this->assertTrue($powder->fresh()->is_blocked);
        $this->assertSame(0, \App\Models\TaskNotification::where('type', 'step.ready')->where('factory_order_id', $powder->id)->count());
        $this->as($this->engineer); $this->approve($bend)->assertOk(); $this->approve($bend)->assertOk();
        $this->assertFalse($powder->fresh()->is_blocked);
        $notifications = \App\Models\TaskNotification::where('type', 'step.ready')->where('factory_order_id', $powder->id)->get();
        $this->assertCount(1, $notifications); $this->assertSame($powderOperator->id, $notifications->first()->user_id);
        $this->assertSame($powder->factory_id, $notifications->first()->data['factory_id']);
        $this->as($powderOperator); $this->act($order, 'finished')->assertOk(); $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_route_edits_validate_cycles_foreign_workshops_stale_edits_and_started_history(): void
    {
        $order = $this->createTask($this->routePayload($this->routePlan('parallel')));
        $before = \App\Support\ProductionRoute::snapshot($order->factoryOrders()->get());
        $plan = $this->routePlan('custom'); $plan['stages'][0]['predecessor_factory_ids'] = [$this->factories['DLD']->id];
        $plan['stages'][1]['predecessor_factory_ids'] = [$this->factories['DXF']->id];
        $this->routeUpdate($order, $plan, $before)->assertUnprocessable()->assertJsonValidationErrors('production_route');
        $plan = $this->routePlan('sequential');
        foreach ([$this->otherEngineer, $this->operator, $this->customer] as $user) { $this->as($user); $this->routeUpdate($order, $plan, $before)->assertForbidden(); }
        $this->as($this->manager); $this->routeUpdate($order, $plan, $before)->assertOk();
        $this->routeUpdate($order, $this->routePlan('parallel'), $before)->assertStatus(409);
        $current = \App\Support\ProductionRoute::snapshot($order->factoryOrders()->get());
        $foreign = $plan; $foreign['stages'][0]['predecessor_factory_ids'] = [999999]; $foreign['mode'] = 'custom';
        $this->routeUpdate($order, $foreign, $current)->assertUnprocessable();
        $this->as($this->operator); $this->act($order, 'confirmed')->assertOk();
        $this->as($this->engineer); $changed = $this->routePlan('custom');
        $changed['stages'][0]['predecessor_factory_ids'] = [$this->factories['DLD']->id];
        $this->routeUpdate($order, $changed, $current)->assertUnprocessable();
        $this->assertSame($current, \App\Support\ProductionRoute::snapshot($order->factoryOrders()->get()));
    }

    public function test_creation_route_validation_rolls_back_task_files_and_notifications(): void
    {
        $plan = $this->routePlan('custom');
        $plan['stages'][0]['predecessor_factory_ids'] = [$this->factories['DLD']->id];
        $plan['stages'][1]['predecessor_factory_ids'] = [$this->factories['DXF']->id];
        $this->as($this->engineer);
        $this->postJson('/api/engineers/engineer', $this->payload($this->routePayload($plan)), $this->headers())->assertUnprocessable();
        $this->assertSame(0, Order::count()); $this->assertSame(0, FactoryOrder::count());
        $this->assertSame(0, \App\Models\TaskNotification::count());
    }

    public function test_route_survives_file_edit_recreated_job_ids_and_migration_retry_without_data_loss(): void
    {
        $data = $this->routePayload($this->routePlan('sequential'));
        $order = $this->createTask($data);
        $snapshot = \App\Support\ProductionRoute::snapshot($order->factoryOrders()->get());
        $oldIds = $order->factoryOrders()->pluck('id')->all();
        unset($data['production_route']);
        $this->putJson('/api/engineers/engineer/'.$order->id, $this->payload($data), $this->headers())->assertOk();
        $steps = $order->factoryOrders()->get();
        $this->assertSame($snapshot, \App\Support\ProductionRoute::snapshot($steps));
        foreach ($steps as $step) $this->assertSame([], array_intersect($step->dependencyIds(), $oldIds));
        $migration = require database_path('migrations/2026_10_22_120000_add_production_routes.php'); $migration->up(); $migration->up();
        $this->assertSame($snapshot, \App\Support\ProductionRoute::snapshot($order->factoryOrders()->get()));
    }

    public function test_unassigned_ready_stage_notifies_only_eligible_company_workshop_operators(): void
    {
        $order = $this->createTask($this->routePayload($this->routePlan('sequential')));
        $bend = $order->factoryOrders()->where('factory_id', $this->factories['DLD']->id)->firstOrFail();
        $this->assign($order, $bend, null)->assertOk();
        $this->as($this->operator); $this->act($order, 'finished')->assertOk();
        $ids = \App\Models\TaskNotification::where('type', 'step.ready')->where('factory_order_id', $bend->id)->pluck('user_id')->all();
        $this->assertSame([$this->bendOperator->id], $ids);
        $this->assertFalse($bend->fresh()->is_blocked);
    }

    public function test_four_company_workshops_and_new_catalog_names_work_with_every_route_mode(): void
    {
        $fourth = $this->factories['IQS']; $fourth->update(['name' => 'Եռակցում և հավաքում']);
        foreach (['sequential', 'parallel', 'custom'] as $mode) {
            $plan = $this->routePlan($mode);
            $plan['stages'][] = ['factory_id' => $fourth->id, 'predecessor_factory_ids' => array_column($plan['stages'], 'factory_id'), 'deadline' => null];
            $order = $this->createTask($this->routePayload($plan));
            $this->assertSame(4, $order->factoryOrders()->count());
            $last = $order->factoryOrders()->where('factory_id', $fourth->id)->firstOrFail();
            $this->assertCount($mode === 'parallel' ? 0 : ($mode === 'sequential' ? 1 : 3), $last->dependencyIds());
            $this->assertSame($mode !== 'parallel', $last->is_blocked);
            $this->assertSame('Եռակցում և հավաքում', $last->factory->name);
        }
    }

    public function test_route_adds_workshops_without_files_with_their_own_settings_and_keeps_them_on_file_edits(): void
    {
        $dxf = $this->factories['DXF']->id; $powder = $this->factories['POWDER']->id;
        $deadline = now()->addDays(5)->toDateString();
        $routing = [['file_id' => $this->files['DXF']->id, 'factory_ids' => [$dxf]]];
        $order = $this->createTask([
            'production_route' => ['mode' => 'sequential', 'stages' => [
                ['factory_id' => $dxf, 'predecessor_factory_ids' => [], 'deadline' => null],
                ['factory_id' => $powder, 'predecessor_factory_ids' => [], 'deadline' => $deadline],
            ]],
            'file_routing' => $routing,
            'workshop_settings' => [['factory_id' => $powder, 'confirmation_required' => false, 'confirmation_method' => null, 'priority' => 'urgent', 'deadline' => $deadline]],
            'factory_operators' => [['factory_id' => $dxf, 'user_id' => $this->operator->id]],
        ]);
        $steps = $order->factoryOrders()->withCount('files')->get()->keyBy('factory_id');
        $this->assertCount(2, $steps);
        $this->assertSame(1, (int) $steps[$dxf]->files_count);
        $this->assertSame(0, (int) $steps[$powder]->files_count);
        $this->assertSame('urgent', $steps[$powder]->priority);
        $this->assertSame($deadline, $steps[$powder]->deadline);
        $this->assertSame([$steps[$dxf]->id], $steps[$powder]->dependencyIds());
        $this->assertTrue($steps[$powder]->is_blocked);

        $this->putJson('/api/engineers/engineer/'.$order->id, $this->payload(['file_routing' => $routing]), $this->headers())->assertOk();
        $after = $order->factoryOrders()->withCount('files')->get()->keyBy('factory_id');
        $this->assertCount(2, $after);
        $this->assertSame(0, (int) $after[$powder]->files_count);
        $this->assertSame('urgent', $after[$powder]->priority);
        $this->assertSame([$after[$dxf]->id], $after[$powder]->dependencyIds());
    }

    public function test_route_only_stages_must_be_active_workshops_of_the_company(): void
    {
        $dxf = $this->factories['DXF']->id;
        $foreign = $this->in($this->otherCompany, fn () => Factory::where('value', 'POWDER')->value('id'));
        foreach ([$foreign, $this->factories['INFO']->id] as $extra) {
            $this->as($this->engineer);
            $this->postJson('/api/engineers/engineer', $this->payload([
                'production_route' => ['mode' => 'parallel', 'stages' => [
                    ['factory_id' => $dxf, 'predecessor_factory_ids' => [], 'deadline' => null],
                    ['factory_id' => $extra, 'predecessor_factory_ids' => [], 'deadline' => null],
                ]],
                'file_routing' => [['file_id' => $this->files['DXF']->id, 'factory_ids' => [$dxf]]],
            ]), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('production_route');
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(0, FactoryOrder::count());
    }

    private function routePlan(string $mode): array
    {
        return ['mode' => $mode, 'stages' => array_map(fn ($key, $index) => ['factory_id' => $this->factories[$key]->id,
            'predecessor_factory_ids' => [], 'deadline' => now()->addDays($index + 1)->toDateString()], ['DXF', 'DLD', 'POWDER'], [0,1,2])];
    }
    private function routePayload(array $plan): array
    {
        return ['production_route' => $plan, 'file_routing' => [['file_id' => $this->files['DXF']->id, 'factory_ids' => array_column($plan['stages'], 'factory_id')]],
            'factory_operators' => [['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->operator->id], ['factory_id' => $this->factories['DLD']->id, 'user_id' => $this->bendOperator->id]]];
    }
    private function routeUpdate(Order $order, array $plan, array $previous)
    {
        return $this->putJson('/api/tasks/'.$order->id.'/production-route', ['production_route' => $plan, 'previous_route' => $previous], $this->headers());
    }

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
        return array_replace(['user_id' => $this->customer->id, 'description' => 'A private task', 'name' => '990.01', 'finish_date' => now()->addDays(7)->toDateString(), 'pmp_id' => $this->pmp->id, 'link_existing_files' => true, 'selected_files' => [['id' => $this->files['DXF']->id, 'quantity' => 2]]], $extra);
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
