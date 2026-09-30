<?php

namespace Tests\Feature;

use App\Mail\OrderCreated;
use App\Models\Factory;
use App\Models\Order;
use App\Models\Pmp;
use App\Models\PmpFiles;
use App\Models\RemoteNumber;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EngineerOrderFilesTest extends TestCase
{
    private User $engineer;
    private User $client;
    private Pmp $pmp;
    private Pmp $otherPmp;
    private RemoteNumber $subgroup;
    private RemoteNumber $otherSubgroup;
    private RemoteNumber $otherPmpSubgroup;
    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->createSchema();
        Mail::fake();
        // These tests exercise controller validation and persistence. Middleware
        // permissions are covered separately by SecurityRoutesTest.
        $this->withoutMiddleware();

        $engineerRole = Role::create(['name' => 'engineer', 'value' => 'Engineer']);
        $clientRole = Role::create(['name' => 'authenticatedUser', 'value' => 'Client']);
        $this->engineer = User::create(['name' => 'QA Engineer', 'email' => 'engineer@example.invalid', 'password' => 'test-password', 'role_id' => $engineerRole->id]);
        $this->client = User::create(['name' => 'QA Client', 'email' => 'client@example.invalid', 'password' => 'test-password', 'role_id' => $clientRole->id]);
        $this->actingAs($this->engineer);
        $this->pmp = Pmp::create(['group' => '990', 'group_name' => 'QA group']);
        $this->otherPmp = Pmp::create(['group' => '991', 'group_name' => 'Other QA group']);
        $this->subgroup = RemoteNumber::create(['pmp_id' => $this->pmp->id, 'remote_number' => '01', 'remote_number_name' => 'Selected']);
        $this->otherSubgroup = RemoteNumber::create(['pmp_id' => $this->pmp->id, 'remote_number' => '02', 'remote_number_name' => 'Other']);
        $this->otherPmpSubgroup = RemoteNumber::create(['pmp_id' => $this->otherPmp->id, 'remote_number' => '01', 'remote_number_name' => 'Other group']);
        $this->factory = Factory::create(['name' => 'INFO', 'value' => 'INFO']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_empty_pmp_cannot_create_an_all_files_order(): void
    {
        $this->assertRejected($this->payload());
    }

    public function test_another_subgroups_files_cannot_create_an_all_files_order(): void
    {
        $this->file($this->otherSubgroup);
        $this->assertRejected($this->payload());
    }

    public function test_empty_selection_is_rejected_even_when_subgroup_has_files(): void
    {
        $this->file($this->subgroup);
        $this->assertRejected($this->payload(['link_existing_files' => true, 'selected_files' => []]));
    }

    public function test_missing_selection_is_rejected_in_selected_file_mode(): void
    {
        $this->file($this->subgroup);
        $this->assertRejected($this->payload(['link_existing_files' => true]));
    }

    public function test_selected_files_must_belong_to_selected_subgroup(): void
    {
        $file = $this->file($this->otherSubgroup);
        $this->assertRejected($this->payload(['link_existing_files' => true, 'selected_files' => [['id' => $file->id, 'quantity' => 2]]]));
    }

    public function test_selected_files_must_belong_to_selected_pmp(): void
    {
        $file = $this->file($this->otherPmpSubgroup);
        $this->assertRejected($this->payload(['link_existing_files' => true, 'selected_files' => [['id' => $file->id, 'quantity' => 2]]]));
    }

    public function test_subgroup_from_another_pmp_is_rejected(): void
    {
        $this->file($this->subgroup);
        $this->assertRejected($this->payload(['remote_number_id' => $this->otherPmpSubgroup->id]), 'remote_number_id');
    }

    public function test_invalid_selected_quantity_is_rejected_without_writes_or_mail(): void
    {
        $file = $this->file($this->subgroup);
        $this->assertRejected($this->payload(['link_existing_files' => true, 'selected_files' => [['id' => $file->id, 'quantity' => 0]]]), 'selected_files.0.quantity');
    }

    public function test_false_flag_creates_order_with_all_files_from_only_selected_subgroup(): void
    {
        $first = $this->file($this->subgroup);
        $otherFactory = Factory::create(['name' => 'DXF', 'value' => 'DXF']);
        $second = $this->file($this->subgroup, $otherFactory);
        $excluded = $this->file($this->otherSubgroup);

        $response = $this->postJson('/api/engineers/engineer', $this->payload());
        $response->assertCreated();
        $order = Order::findOrFail($response->json('order.id'));
        $this->assertSame([$first->id, $second->id], $order->selectedFiles()->orderBy('pmp_file_id')->pluck('pmp_file_id')->all());
        $this->assertSame([1, 1], $order->selectedFiles()->pluck('quantity')->all());
        $this->assertSame(2, $order->factoryOrders()->count());
        $this->assertDatabaseMissing('selected_files', ['pmp_file_id' => $excluded->id]);
        Mail::assertSent(OrderCreated::class, 1);
    }

    public function test_true_flag_keeps_only_selected_files_quantities_metadata_and_operator(): void
    {
        $file = $this->file($this->subgroup);
        $excluded = $this->file($this->subgroup);
        $operator = User::create(['name' => 'QA Operator', 'email' => 'operator@example.invalid', 'password' => 'test-password', 'factory_id' => $this->factory->id]);
        $response = $this->postJson('/api/engineers/engineer', $this->payload([
            'link_existing_files' => true,
            'selected_files' => [['id' => $file->id, 'quantity' => 4]],
            'factory_operators' => [['factory_id' => $this->factory->id, 'user_id' => $operator->id]],
        ]));
        $response->assertCreated();
        $orderId = $response->json('order.id');
        $this->assertDatabaseHas('selected_files', ['order_id' => $orderId, 'pmp_file_id' => $file->id, 'quantity' => 4]);
        $this->assertDatabaseMissing('selected_files', ['pmp_file_id' => $excluded->id]);
        $this->assertDatabaseHas('factory_orders', ['order_id' => $orderId, 'operator_id' => $operator->id]);
        $this->assertDatabaseHas('factory_order_files', ['pmp_files_id' => $file->id, 'quantity' => 4, 'material_type' => 'QA material', 'thickness' => '1.5']);
        Mail::assertSent(OrderCreated::class, 1);
    }

    public function test_legacy_group_only_request_still_works_when_files_exist(): void
    {
        $this->file($this->subgroup);
        $this->file($this->otherSubgroup);
        $response = $this->postJson('/api/engineers/engineer', $this->payload(['remote_number_id' => null]));
        $response->assertCreated();
        $this->assertDatabaseCount('selected_files', 2);
    }

    public function test_editing_without_new_selection_retains_existing_order_files(): void
    {
        $file = $this->file($this->subgroup);
        $created = $this->postJson('/api/engineers/engineer', $this->payload())->assertCreated();
        $orderId = $created->json('order.id');
        $this->putJson('/api/engineers/engineer/' . $orderId, $this->payload(['description' => 'Updated description', 'selected_files' => []]))->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'description' => 'Updated description']);
        $this->assertDatabaseHas('selected_files', ['order_id' => $orderId, 'pmp_file_id' => $file->id]);
        $this->assertDatabaseCount('factory_order_files', 1);
    }

    public function test_editing_cannot_replace_selection_with_another_subgroups_files(): void
    {
        $file = $this->file($this->subgroup);
        $other = $this->file($this->otherSubgroup);
        $created = $this->postJson('/api/engineers/engineer', $this->payload())->assertCreated();
        $orderId = $created->json('order.id');
        $this->putJson('/api/engineers/engineer/' . $orderId, $this->payload(['link_existing_files' => true, 'selected_files' => [['id' => $other->id, 'quantity' => 2]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('selected_files');
        $this->assertDatabaseHas('selected_files', ['order_id' => $orderId, 'pmp_file_id' => $file->id]);
        $this->assertDatabaseMissing('selected_files', ['order_id' => $orderId, 'pmp_file_id' => $other->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'user_id' => $this->client->id,
            'description' => 'QA regression order',
            'name' => '990.01',
            'finish_date' => '2026-10-15 12:00:00',
            'pmp_id' => $this->pmp->id,
            'remote_number_id' => $this->subgroup->id,
            'link_existing_files' => false,
        ], $overrides);
    }

    private function file(RemoteNumber $subgroup, ?Factory $factory = null): PmpFiles
    {
        return PmpFiles::create([
            'pmp_id' => $subgroup->pmp_id, 'remote_number_id' => $subgroup->id,
            'factory_id' => ($factory ?? $this->factory)->id,
            'path' => 'qa/note.txt', 'original_name' => 'QA note.txt',
            'material_type' => 'QA material', 'thickness' => '1.5',
        ]);
    }

    private function assertRejected(array $payload, string $field = 'selected_files'): void
    {
        $this->postJson('/api/engineers/engineer', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        foreach (['orders', 'selected_files', 'factory_orders', 'factory_order_files', 'order_numbers', 'prefix_codes', 'dates', 'order_number_sequences'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Mail::assertNothingSent();
    }

    private function createSchema(): void
    {
        $tables = [
            'roles' => fn (Blueprint $t) => $t->string('name'),
            'clients' => fn (Blueprint $t) => $t->unsignedBigInteger('user_id'),
            'pmps' => function (Blueprint $t) { $t->string('group'); $t->string('group_name'); },
            'remote_numbers' => function (Blueprint $t) { $t->unsignedBigInteger('pmp_id'); $t->string('remote_number'); $t->string('remote_number_name'); },
            'factories' => function (Blueprint $t) { $t->string('name'); $t->string('value'); },
            'users' => function (Blueprint $t) {
                $t->string('name'); $t->string('email'); $t->string('password');
                $t->unsignedBigInteger('role_id')->nullable(); $t->unsignedBigInteger('factory_id')->nullable();
                $t->timestamp('email_verified_at')->nullable(); $t->rememberToken();
            },
            'pmp_files' => function (Blueprint $t) {
                $t->unsignedBigInteger('pmp_id'); $t->unsignedBigInteger('remote_number_id')->nullable(); $t->unsignedBigInteger('factory_id');
                $t->string('path'); $t->string('original_name'); $t->integer('quantity')->nullable();
                $t->string('material_type')->nullable(); $t->string('thickness')->nullable();
            },
            'orders' => function (Blueprint $t) {
                $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('creator_id'); $t->unsignedBigInteger('remote_number_id')->nullable();
                $t->string('name'); $t->text('description'); $t->string('status'); $t->boolean('link_existing_files');
            },
            'selected_files' => function (Blueprint $t) { $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('pmp_file_id'); $t->integer('quantity'); },
            'factory_orders' => function (Blueprint $t) {
                $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('factory_id'); $t->unsignedBigInteger('operator_id')->nullable();
                $t->string('status'); $t->boolean('canceling');
                foreach (['cancel_date', 'finish_date', 'operator_finish_date', 'admin_confirmation_date'] as $field) $t->timestamp($field)->nullable();
            },
            'factory_order_files' => function (Blueprint $t) {
                $t->unsignedBigInteger('factory_order_id'); $t->unsignedBigInteger('pmp_files_id'); $t->integer('quantity');
                $t->string('material_type')->nullable(); $t->string('thickness')->nullable();
            },
            'order_numbers' => function (Blueprint $t) { $t->unsignedBigInteger('order_id'); $t->string('number'); },
            'prefix_codes' => function (Blueprint $t) { $t->unsignedBigInteger('order_id'); $t->string('code'); },
            'dates' => function (Blueprint $t) { $t->unsignedBigInteger('order_id'); $t->timestamp('finish_date'); },
            'order_number_sequences' => function (Blueprint $t) { $t->string('period')->unique(); $t->unsignedInteger('last_number'); },
        ];
        foreach ($tables as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($name, $columns): void {
                $table->id();
                $columns($table);
                if ($name === 'roles') $table->string('value');
                $table->timestamps();
            });
        }
    }
}
