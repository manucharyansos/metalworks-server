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
 * The whole cycle: the engineer creates a task, an operator works on it, the
 * engineer is notified, and rejecting a job, moving a deadline or finishing
 * all need the creating engineer's consent.
 */
class TaskApprovalCycleTest extends TestCase
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

    // ── Notifications ───────────────────────────────────────────────────────

    public function test_creating_engineer_is_notified_about_operator_actions_and_nobody_else_is(): void
    {
        $order = $this->createTask($this->laser(['confirmation_required' => true, 'confirmation_method' => 'text']));
        $step = $order->factoryOrders()->firstOrFail();

        // The chosen operator hears about the new job; the engineer is not told about their own action.
        $this->notes($this->operator)->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.type', 'step.assigned')->assertJsonPath('data.0.order_id', $order->id);
        $this->notes($this->engineer)->assertJsonPath('unread_count', 0);
        $this->notes($this->secondOperator)->assertJsonPath('unread_count', 0);

        $this->as($this->operator); $this->act($order, 'confirmed')->assertOk();
        $this->notes($this->operator)->assertJsonPath('unread_count', 1); // own action is not announced to oneself
        $this->notes($this->engineer)->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.type', 'step.status_changed')
            ->assertJsonPath('data.0.data.to_status', 'confirmed')->assertJsonPath('data.0.factory_order_id', $step->id)->assertJsonPath('data.0.actor_id', $this->operator->id);
        $this->notes($this->otherEngineer)->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'data');

        $this->as($this->operator); $this->act($order, 'finished', ['evidence_text' => 'Cut and checked'])->assertOk();
        $result = $this->notes($this->engineer)->assertJsonPath('unread_count', 2)->assertJsonPath('data.0.type', 'step.finished_review');
        $this->assertStringContainsString('հաստատ', $result->json('data.0.message'));

        // Marking read only touches the caller's own notifications.
        $foreign = $this->notes($this->operator)->json('data.0.id');
        $this->as($this->engineer);
        $this->postJson('/api/notifications/read', ['ids' => [$foreign]], $this->headers())->assertOk()->assertJsonPath('unread_count', 2);
        $this->assertNull(TaskNotification::withoutGlobalScopes()->find($foreign)->read_at);
        $this->postJson('/api/notifications/read', ['ids' => [$result->json('data.0.id')]], $this->headers())->assertOk()->assertJsonPath('unread_count', 1);
        $this->postJson('/api/notifications/read', [], $this->headers())->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertNull(TaskNotification::withoutGlobalScopes()->find($foreign)->read_at);
    }

    public function test_notifications_are_private_to_their_recipient_and_need_a_login(): void
    {
        $this->createTask($this->laser());
        $this->app['auth']->forgetGuards();
        app(CompanyContext::class)->set(null);
        $this->getJson('/api/notifications', $this->headers())->assertUnauthorized();
        $this->notes($this->bendOperator)->assertJsonCount(0, 'data');
        $this->notes($this->operator)->assertJsonCount(1, 'data');
    }

    // ── Rejecting a job needs the creating engineer ─────────────────────────

    public function test_reject_is_only_a_request_until_the_creating_engineer_approves_it(): void
    {
        $order = $this->createTask($this->laser());
        $step = $order->factoryOrders()->firstOrFail();

        $this->as($this->operator);
        $this->act($order, 'canceled')->assertUnprocessable()->assertJsonValidationErrors('factory_order.canceling');
        $this->act($order, 'canceled', ['canceling' => 'no_material', 'request_note' => 'No sheets left'])->assertOk()
            ->assertJsonPath('change_request.type', 'reject')->assertJsonPath('change_request.status', 'pending')
            ->assertJsonPath('factory_orders.0.pending_request.reason', 'no_material')->assertJsonPath('factory_orders.0.status', 'pending');
        $this->assertSame('pending', $step->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->act($order, 'date_changed', ['cancel_date' => '2099-01-01'])->assertUnprocessable()->assertJsonValidationErrors('factory_order.status');
        $request = $this->lastRequest($order);

        $this->notes($this->engineer)->assertJsonPath('data.0.type', 'request.submitted')->assertJsonPath('data.0.data.request_type', 'reject')->assertJsonPath('data.0.data.reason', 'no_material');
        $this->as($this->engineer);
        $this->getJson('/api/engineers/engineer?confirmation=waiting', $this->headers())->assertOk()->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.factory_orders.0.pending_request.operator_note', 'No sheets left')->assertJsonPath('orders.0.factory_orders.0.pending_request.requester.id', $this->operator->id);
        // The creator cannot rebuild the task while an operator waits for an answer.
        $this->putJson('/api/engineers/engineer/'.$order->id, $this->payload(), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('order');

        foreach ([$this->otherEngineer, $this->admin, $this->operator, $this->customer] as $outsider) {
            $this->as($outsider); $this->decide($request, 'approve')->assertForbidden();
        }
        $this->assertSame('pending', $request->fresh()->status);

        $this->as($this->engineer);
        $this->decide($request, 'approve')->assertOk()->assertJsonPath('request.status', 'approved')->assertJsonPath('request.resolution', 'canceled')
            ->assertJsonPath('order.factory_orders.0.status', 'canceled')->assertJsonPath('order.factory_orders.0.pending_request', null)
            ->assertJsonPath('order.status', 'canceled'); // nothing was produced, so the task is canceled, not completed
        $this->decide($request, 'approve')->assertOk();                  // a double click changes nothing
        $this->decide($request, 'decline')->assertUnprocessable();       // but the decision cannot be flipped
        $this->assertSame('canceled', $step->fresh()->status);
        $this->assertSame('no_material', $step->fresh()->canceling);
        $this->assertSame(1, TaskNotification::withoutGlobalScopes()->where('user_id', $this->operator->id)->where('type', 'request.approved')->count());

        $this->notes($this->operator)->assertJsonPath('data.0.type', 'request.approved');
        $this->as($this->operator);
        $this->act($order, 'confirmed')->assertUnprocessable(); // an approved rejection cannot be quietly undone
        $this->assertSame('canceled', $step->fresh()->status);
    }

    public function test_declined_reject_keeps_the_job_with_the_operator_and_explains_why(): void
    {
        $order = $this->createTask($this->laser());
        $step = $order->factoryOrders()->firstOrFail();
        $this->as($this->operator); $this->act($order, 'canceled', ['canceling' => 'unclear'])->assertOk();
        $request = $this->lastRequest($order);
        $this->as($this->engineer);
        $this->decide($request, 'decline', ['note' => 'The drawing is final, please start.'])->assertOk()->assertJsonPath('request.status', 'declined')
            ->assertJsonPath('order.factory_orders.0.status', 'pending')->assertJsonPath('order.factory_orders.0.pending_request', null);
        $this->assertSame($this->operator->id, $step->fresh()->operator_id);
        $result = $this->notes($this->operator)->assertJsonPath('data.0.type', 'request.declined')->assertJsonPath('data.0.data.decision_note', 'The drawing is final, please start.');
        $this->assertStringContainsString('The drawing is final', $result->json('data.0.message'));
        $this->as($this->operator);
        $this->act($order, 'confirmed')->assertOk(); // the operator carries on
        $this->act($order, 'canceled', ['canceling' => 'other'])->assertOk()->assertJsonPath('change_request.status', 'pending'); // and may ask again
    }

    public function test_management_can_reopen_an_approved_rejection_and_the_creator_can_reassign_it(): void
    {
        $order = $this->createTask($this->laser());
        $step = $order->factoryOrders()->firstOrFail();
        $this->as($this->operator); $this->act($order, 'canceled', ['canceling' => 'other'])->assertOk();
        $this->as($this->engineer); $this->decide($this->lastRequest($order), 'approve')->assertOk()->assertJsonPath('order.status', 'canceled');
        $this->as($this->admin);
        $this->putJson('/api/factories/updateOrder/'.$order->id, ['factory_id' => $step->factory_id, 'factory_order' => ['status' => 'confirmed']], $this->headers())
            ->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('completed_at', null);
        $this->as($this->engineer);
        $this->assign($order, $step, $this->secondOperator->id)->assertOk()->assertJsonPath('order.factory_orders.0.operator_id', $this->secondOperator->id);
    }

    public function test_approving_a_reject_can_hand_the_job_to_another_operator_to_balance_workload(): void
    {
        $order = $this->createTask($this->laser(['confirmation_required' => true, 'confirmation_method' => 'text']));
        $step = $order->factoryOrders()->firstOrFail();
        $this->as($this->operator); $this->act($order, 'confirmed')->assertOk(); $this->act($order, 'canceled', ['canceling' => 'other', 'request_note' => 'Overloaded this week'])->assertOk();
        $request = $this->lastRequest($order);

        $this->as($this->engineer);
        $this->decide($request, 'approve', ['resolution' => 'reassigned'])->assertUnprocessable()->assertJsonValidationErrors('operator_id');
        $this->decide($request, 'approve', ['resolution' => 'reassigned', 'operator_id' => $this->operator->id])->assertUnprocessable()->assertJsonValidationErrors('operator_id');
        $this->decide($request, 'approve', ['resolution' => 'reassigned', 'operator_id' => $this->bendOperator->id])->assertUnprocessable()->assertJsonValidationErrors('operator_id');
        $this->decide($request, 'approve', ['resolution' => 'melted'])->assertUnprocessable()->assertJsonValidationErrors('resolution');
        $this->assertSame('pending', $request->fresh()->status);

        $this->decide($request, 'approve', ['resolution' => 'reassigned', 'operator_id' => $this->secondOperator->id])->assertOk()
            ->assertJsonPath('request.resolution', 'reassigned')->assertJsonPath('order.factory_orders.0.operator_id', $this->secondOperator->id)
            ->assertJsonPath('order.factory_orders.0.status', 'pending')->assertJsonPath('order.status', 'pending');
        $this->assertTrue($step->fresh()->confirmation_required);
        $this->assertSame('', (string) $step->fresh()->canceling);

        $types = fn (User $user) => TaskNotification::withoutGlobalScopes()->where('user_id', $user->id)->pluck('type')->all();
        $this->assertContains('step.assigned', $types($this->secondOperator));
        $this->assertEqualsCanonicalizing(['step.assigned', 'request.approved', 'step.unassigned'], $types($this->operator));

        $this->as($this->operator);
        $this->getJson('/api/orders/'.$order->id, $this->headers())->assertForbidden();
        $this->act($order, 'confirmed')->assertForbidden();
        $this->as($this->secondOperator);
        $this->act($order, 'confirmed')->assertOk();
        $this->act($order, 'finished', ['evidence_text' => 'Cut by the second operator'])->assertOk();
    }

    public function test_requester_can_withdraw_but_nobody_else_can(): void
    {
        $order = $this->createTask($this->laser());
        $this->as($this->operator); $this->act($order, 'canceled', ['canceling' => 'wrong_data'])->assertOk();
        $request = $this->lastRequest($order);

        $this->as($this->secondOperator); $this->withdraw($request)->assertForbidden();
        $this->as($this->bendOperator); $this->withdraw($request)->assertForbidden();
        $this->as($this->engineer); $this->withdraw($request)->assertForbidden(); // the engineer answers; only the operator withdraws
        $this->as($this->operator);
        $this->withdraw($request)->assertOk()->assertJsonPath('factory_orders.0.pending_request', null)->assertJsonPath('factory_orders.0.status', 'pending');
        $this->withdraw($request)->assertOk();
        $this->assertSame('withdrawn', $request->fresh()->status);
        $this->assertSame(1, TaskNotification::withoutGlobalScopes()->where('user_id', $this->engineer->id)->where('type', 'request.withdrawn')->count());

        $this->as($this->engineer); $this->decide($request, 'approve')->assertUnprocessable();
        $this->as($this->operator); $this->act($order, 'canceled', ['canceling' => 'other'])->assertOk(); // a fresh request is possible again
    }

    // ── Moving a deadline needs the creating engineer ───────────────────────

    public function test_deadline_moves_only_after_the_creating_engineer_agrees(): void
    {
        $order = $this->createTask($this->laser(['finish_date' => '2098-01-01']));
        $step = $order->factoryOrders()->firstOrFail();
        $this->as($this->operator);
        $this->act($order, 'date_changed')->assertUnprocessable()->assertJsonValidationErrors('factory_order.cancel_date');
        $this->act($order, 'date_changed', ['cancel_date' => '2000-01-01'])->assertUnprocessable()->assertJsonValidationErrors('factory_order.cancel_date');
        $this->act($order, 'date_changed', ['cancel_date' => '2099-02-03', 'request_note' => 'Machine maintenance'])->assertOk()
            ->assertJsonPath('change_request.type', 'deadline')->assertJsonPath('change_request.proposed_date', '2099-02-03');
        $request = $this->lastRequest($order);
        $this->assertSame('2098-01-01', substr((string) $order->fresh()->dates->finish_date, 0, 10), 'asking must not move the deadline');
        $this->assertSame('pending', $step->fresh()->status);
        $this->notes($this->engineer)->assertJsonPath('data.0.type', 'request.submitted')->assertJsonPath('data.0.data.proposed_date', '2099-02-03');

        $this->as($this->engineer);
        $this->decide($request, 'approve')->assertOk()->assertJsonPath('request.resolution', 'deadline_changed')
            ->assertJsonPath('order.factory_orders.0.status', 'date_changed')->assertJsonPath('order.factory_orders.0.cancel_date', fn ($date) => str_starts_with((string) $date, '2099-02-03'));
        $this->assertSame('2099-02-03', substr((string) $order->fresh()->dates->finish_date, 0, 10));
        $this->assertSame('pending', $order->fresh()->status);
        $this->notes($this->operator)->assertJsonPath('data.0.type', 'request.approved')->assertJsonPath('data.0.data.proposed_date', '2099-02-03');

        $this->as($this->operator); // work simply continues
        $this->act($order, 'confirmed')->assertOk();
        $this->act($order, 'finished')->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_declined_deadline_leaves_the_deadline_untouched(): void
    {
        $order = $this->createTask($this->laser(['finish_date' => '2098-01-01']));
        $this->as($this->operator); $this->act($order, 'date_changed', ['cancel_date' => '2099-02-03'])->assertOk();
        $this->as($this->engineer); $this->decide($this->lastRequest($order), 'decline', ['note' => 'The customer needs it on time'])->assertOk()->assertJsonPath('request.status', 'declined');
        $this->assertSame('2098-01-01', substr((string) $order->fresh()->dates->finish_date, 0, 10));
        $this->assertSame('pending', $order->factoryOrders()->firstOrFail()->status);
        $this->notes($this->operator)->assertJsonPath('data.0.type', 'request.declined');
    }

    // ── Overrides keep requests consistent ──────────────────────────────────

    public function test_new_operator_or_manager_override_closes_a_waiting_request(): void
    {
        $order = $this->createTask($this->laser());
        $step = $order->factoryOrders()->firstOrFail();
        $this->as($this->operator); $this->act($order, 'date_changed', ['cancel_date' => '2099-02-03'])->assertOk();

        $this->as($this->engineer);
        $this->assign($order, $step, $this->secondOperator->id)->assertOk()->assertJsonPath('order.factory_orders.0.pending_request', null);
        $this->assertSame('withdrawn', $this->lastRequest($order)->status);
        $this->assertContains('step.assigned', TaskNotification::withoutGlobalScopes()->where('user_id', $this->secondOperator->id)->pluck('type')->all());
        $this->assertContains('step.unassigned', TaskNotification::withoutGlobalScopes()->where('user_id', $this->operator->id)->pluck('type')->all());

        // Management still acts directly (no consent loop) and the engineer is told.
        $this->as($this->secondOperator); $this->act($order, 'canceled', ['canceling' => 'other'])->assertOk();
        $this->as($this->admin);
        $this->putJson('/api/factories/updateOrder/'.$order->id, ['factory_id' => $step->factory_id, 'factory_order' => ['status' => 'canceled', 'canceling' => 'other']], $this->headers())
            ->assertOk()->assertJsonPath('factory_orders.0.status', 'canceled')->assertJsonMissingPath('change_request');
        $this->assertSame('withdrawn', $this->lastRequest($order)->status);
        $this->notes($this->engineer)->assertJsonPath('data.0.type', 'step.status_changed')->assertJsonPath('data.0.data.to_status', 'canceled');
    }

    public function test_one_finished_and_one_rejected_workshop_still_completes_the_task(): void
    {
        $order = $this->createTask($this->laser([
            'selected_files' => [['id' => $this->files['DXF']->id, 'quantity' => 1], ['id' => $this->files['DLD']->id, 'quantity' => 1]],
            'factory_operators' => [['factory_id' => $this->factories['DXF']->id, 'user_id' => $this->operator->id], ['factory_id' => $this->factories['DLD']->id, 'user_id' => $this->bendOperator->id]],
        ]));
        $bend = $order->factoryOrders()->where('factory_id', $this->factories['DLD']->id)->firstOrFail();
        $this->as($this->operator); $this->act($order, 'finished')->assertOk()->assertJsonPath('status', 'pending');
        $this->as($this->bendOperator); $this->act($order, 'canceled', ['canceling' => 'other'])->assertOk();
        $this->as($this->engineer); $this->decide($this->lastRequest($order), 'approve')->assertOk()->assertJsonPath('order.status', 'completed');
        $this->assertSame('canceled', $bend->fresh()->status);
    }

    // ── Finishing needs the creating engineer, who may send it back ─────────

    public function test_a_deadline_request_that_expired_while_waiting_cannot_be_approved(): void
    {
        $order = $this->createTask($this->laser());
        $original = $order->dates->finish_date;
        $this->as($this->operator);
        $this->act($order, 'date_changed', ['cancel_date' => today()->addDay()->format('Y-m-d')])->assertOk();
        $request = $this->lastRequest($order);
        $this->travel(2)->days();
        try {
            $this->as($this->engineer);
            $this->decide($request, 'approve')->assertUnprocessable()->assertJsonValidationErrors('proposed_date');
            $this->assertSame('pending', $request->fresh()->status);
            $this->assertSame($original, $order->dates()->first()->finish_date);
        } finally { $this->travelBack(); }
    }

    public function test_waiting_filter_does_not_include_other_tasks_without_a_pending_request(): void
    {
        $waiting = $this->createTask($this->laser());
        $this->createTask($this->laser());
        $this->as($this->operator); $this->act($waiting, 'canceled', ['canceling' => 'other'])->assertOk();
        $this->as($this->engineer);
        $this->getJson('/api/engineers/engineer?confirmation=waiting', $this->headers())->assertOk()
            ->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.id', $waiting->id);
    }

    public function test_notifications_and_requests_remain_private_when_the_same_users_switch_company(): void
    {
        $order = $this->createTask($this->laser());
        $this->as($this->operator); $this->act($order, 'canceled', ['canceling' => 'other'])->assertOk();
        $request = $this->lastRequest($order);
        $notification = TaskNotification::withoutGlobalScopes()->where('user_id', $this->engineer->id)->firstOrFail();
        $other = Company::create(['name' => 'Other Works', 'slug' => 'other-works']);
        $this->in($other, function () { (new FactorySeeder)->run(); (new FactoryOrderStatusSeeder)->run(); });
        foreach ([$this->engineer, $this->operator] as $user) {
            $membership = CompanyMembership::create(['company_id' => $other->id, 'user_id' => $user->id,
                'role_id' => $user->getRawOriginal('role_id'),
                'factory_id' => $user->id === $this->operator->id ? $this->in($other, fn () => Factory::where('value', 'DXF')->value('id')) : null]);
            $membership->permissions()->sync(Permission::whereIn('slug', ['orders.view', 'factory.order_update'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        }
        $headers = ['X-Company-ID' => (string) $other->id, 'Accept' => 'application/json'];
        $this->as($this->engineer);
        $this->getJson('/api/notifications', $headers)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        $this->postJson('/api/notifications/read', ['ids' => [$notification->id]], $headers)->assertOk();
        $this->assertNull($notification->fresh()->read_at);
        $this->postJson('/api/engineers/task-requests/'.$request->id.'/decision', ['decision' => 'approve'], $headers)->assertNotFound();
        $this->as($this->operator);
        $this->postJson('/api/task-requests/'.$request->id.'/withdraw', [], $headers)->assertNotFound();
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_engineer_can_return_finished_work_with_a_note_and_the_operator_resubmits(): void
    {
        $order = $this->createTask($this->laser(['confirmation_required' => true, 'confirmation_method' => 'text']));
        $step = $order->factoryOrders()->firstOrFail();
        $this->as($this->engineer); $this->return($step, ['note' => 'Too early'])->assertUnprocessable()->assertJsonValidationErrors('confirmation'); // nothing to review yet
        $this->as($this->operator); $this->act($order, 'finished', ['evidence_text' => 'First try'])->assertOk()->assertJsonPath('factory_orders.0.awaiting_engineer_confirmation', true);

        $this->as($this->engineer);
        $this->return($step, [])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->return($step, ['note' => '   '])->assertUnprocessable();
        $this->as($this->otherEngineer); $this->return($step, ['note' => 'Not mine'])->assertForbidden();
        $this->as($this->admin); $this->return($step, ['note' => 'Not mine'])->assertForbidden();
        $this->assertTrue($step->fresh()->awaiting_engineer_confirmation);

        $this->as($this->engineer);
        $this->return($step, ['note' => 'Wrong hole size'])->assertOk()
            ->assertJsonPath('order.factory_orders.0.status', 'confirmed')->assertJsonPath('order.factory_orders.0.awaiting_engineer_confirmation', false)
            ->assertJsonPath('order.factory_orders.0.return_note', 'Wrong hole size')->assertJsonPath('order.factory_orders.0.evidence_text', null);
        $log = $order->logs()->where('action', 'factory_order.finish_returned')->firstOrFail();
        $this->assertSame('First try', $log->meta['previous_evidence_text']);
        $this->approve($step)->assertUnprocessable();                    // nothing left to approve
        $this->return($step, ['note' => 'Again'])->assertUnprocessable(); // and nothing to return
        $this->notes($this->operator)->assertJsonPath('data.0.type', 'step.finish_returned')->assertJsonPath('data.0.data.note', 'Wrong hole size');

        $this->as($this->operator);
        $this->act($order, 'finished', ['evidence_text' => 'Second try, hole corrected'])->assertOk()
            ->assertJsonPath('factory_orders.0.awaiting_engineer_confirmation', true)->assertJsonPath('factory_orders.0.return_note', null);
        $this->as($this->engineer);
        $this->approve($step)->assertOk()->assertJsonPath('order.status', 'completed');
        $this->notes($this->operator)->assertJsonPath('data.0.type', 'step.finish_confirmed');
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
