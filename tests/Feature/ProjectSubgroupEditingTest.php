<?php

namespace Tests\Feature;

use App\Models\{Company, CompanyMembership, Factory, Order, Permission, Pmp, PmpFiles, RemoteNumber, Role, SelectedFile, User};
use App\Support\CompanyContext;
use Database\Seeders\{FactorySeeder, FactoryFileExtensionSeeder, FactoryOrderStatusSeeder, RoleTableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail, Schema, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectSubgroupEditingTest extends TestCase
{
    use RefreshDatabase;
    private const EDITOR = ['pmp.view', 'pmp.update', 'pmp_files.view', 'pmp_files.delete'];
    private Company $company;
    private Company $other;
    private User $engineer;
    private User $client;
    private Pmp $pmp;
    private RemoteNumber $remote;
    private array $factories;
    private bool $native = false;
    protected function beforeRefreshingDatabase(): void { $this->native = \Illuminate\Database\Schema\Builder::$alwaysUsesNativeSchemaOperationsIfPossible; if (DB::getDriverName() === 'mysql') Schema::useNativeSchemaOperationsIfPossible(); }
    protected function afterRefreshingDatabase(): void { Schema::useNativeSchemaOperationsIfPossible($this->native); }
    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RoleTableSeeder::class);
        $this->company = Company::where('slug', 'metalworks')->firstOrFail();
        $this->other = Company::create(['name' => 'Private factory', 'slug' => 'private-factory']);
        foreach ([$this->company, $this->other] as $company) app(CompanyContext::class)->run($company, function () { (new FactorySeeder)->run(); (new FactoryFileExtensionSeeder)->run(); (new FactoryOrderStatusSeeder)->run(); });
        $this->factories = $this->in(fn () => Factory::get()->keyBy('value')->all());
        $this->engineer = $this->account('engineer', self::EDITOR);
        $this->client = $this->account('authenticatedUser');
        $this->pmp = $this->in(fn () => Pmp::create(['group' => '987', 'group_name' => 'Gates', 'admin_confirmation' => false]));
        $this->remote = $this->subgroup('01', 'Left gate');
        Storage::fake('private'); Storage::fake('public'); Mail::fake();
    }

    public function test_the_opened_subgroup_and_its_group_are_renamed_but_keep_their_codes_and_files(): void
    {
        $file = $this->file($this->remote, 'part.dxf');
        $this->as($this->engineer);
        $this->putJson($this->url($this->remote), ['group_name' => '  Garden gates  ', 'remote_number_name' => 'Left wing'], $this->headers())
            ->assertOk()
            ->assertJsonPath('pmp.group', '987')
            ->assertJsonPath('pmp.group_name', 'Garden gates')
            ->assertJsonPath('pmp.remote_number.0.remote_number', '01')
            ->assertJsonPath('pmp.remote_number.0.remote_number_name', 'Left wing')
            ->assertJsonPath('pmp.files.0.id', $file->id);
        $this->assertDatabaseHas('pmp_files', ['id' => $file->id, 'path' => $file->path]);
        Storage::disk('private')->assertExists($file->path);

        $right = $this->subgroup('02', 'Right wing');
        $this->putJson($this->url($right), ['group_name' => 'Garden gates', 'remote_number_name' => 'Left wing'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('remote_number_name');
        $this->in(fn () => Pmp::create(['group' => '988', 'group_name' => 'Fences', 'admin_confirmation' => false]));
        $this->putJson($this->url($this->remote), ['group_name' => 'Fences', 'remote_number_name' => 'Left wing'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('group_name');
        $this->putJson($this->url($this->remote), ['group_name' => ' ', 'remote_number_name' => 'Left wing'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('group_name');
        $this->assertSame('Garden gates', $this->pmp->fresh()->group_name);
    }

    public function test_an_old_duplicate_group_name_does_not_block_renaming_a_subgroup(): void
    {
        $twin = $this->in(fn () => Pmp::create(['group' => '989', 'group_name' => 'Gates', 'admin_confirmation' => false]));
        $part = $this->in(fn () => RemoteNumber::create(['pmp_id' => $twin->id, 'remote_number' => '01', 'remote_number_name' => 'Frame']));
        $this->as($this->engineer);
        $this->putJson($this->url($part), ['group_name' => 'Gates', 'remote_number_name' => 'Welded frame'], $this->headers())
            ->assertOk()->assertJsonPath('pmp.remote_number.0.remote_number_name', 'Welded frame');
    }

    public function test_renaming_and_deleting_need_the_project_rights_and_stay_inside_the_company(): void
    {
        $viewer = $this->account('engineer', ['pmp.view', 'pmp_files.view', 'pmp_files.delete']);
        $this->as($viewer);
        $this->putJson($this->url($this->remote), ['group_name' => 'Stolen', 'remote_number_name' => 'Stolen'], $this->headers())->assertForbidden();
        $this->deleteJson($this->url($this->remote), [], $this->headers())->assertForbidden();
        $renamer = $this->account('engineer', ['pmp.view', 'pmp.update', 'pmp_files.view']);
        $this->as($renamer);
        $this->getJson($this->url($this->remote).'/deletion', $this->headers())->assertForbidden();
        $this->deleteJson($this->url($this->remote), [], $this->headers())->assertForbidden();
        $this->deleteJson('/api/engineers/pmps/'.$this->pmp->id, [], $this->headers())->assertForbidden();

        $foreign = app(CompanyContext::class)->run($this->other, function () {
            $pmp = Pmp::create(['group' => '987', 'group_name' => 'Foreign', 'admin_confirmation' => false]);
            return RemoteNumber::create(['pmp_id' => $pmp->id, 'remote_number' => '01', 'remote_number_name' => 'Foreign part']);
        });
        $this->as($this->engineer);
        $this->putJson($this->url($foreign), ['group_name' => 'Stolen', 'remote_number_name' => 'Stolen'], $this->headers())->assertNotFound();
        $this->deleteJson($this->url($foreign), [], $this->headers())->assertNotFound();
        $this->assertDatabaseHas('remote_numbers', ['id' => $foreign->id, 'remote_number_name' => 'Foreign part']);
        $this->assertDatabaseHas('remote_numbers', ['id' => $this->remote->id, 'remote_number_name' => 'Left gate']);
    }

    public function test_an_unused_subgroup_goes_with_its_files_and_the_last_one_takes_the_empty_group(): void
    {
        $first = $this->file($this->remote, 'left.dxf');
        $right = $this->subgroup('02', 'Right gate');
        $kept = $this->file($right, 'right.dxf');
        $this->as($this->engineer);
        $this->getJson($this->url($this->remote).'/deletion', $this->headers())->assertOk()
            ->assertJson(['files' => 1, 'tasks' => [], 'tasks_total' => 0, 'removes_group' => false, 'can_delete' => true]);
        $this->deleteJson($this->url($this->remote), [], $this->headers())->assertOk()->assertJsonPath('group_deleted', false);
        $this->assertDatabaseMissing('remote_numbers', ['id' => $this->remote->id]);
        $this->assertDatabaseMissing('pmp_files', ['id' => $first->id]);
        Storage::disk('private')->assertMissing($first->path);
        Storage::disk('private')->assertMissing('companies/'.$this->company->id.'/PMP_987.01');
        Storage::disk('private')->assertExists($kept->path);
        $this->assertDatabaseHas('pmps', ['id' => $this->pmp->id]);

        $this->getJson($this->url($right).'/deletion', $this->headers())->assertOk()->assertJsonPath('removes_group', true);
        $this->deleteJson($this->url($right), [], $this->headers())->assertOk()->assertJsonPath('group_deleted', true);
        $this->assertDatabaseMissing('pmps', ['id' => $this->pmp->id]);
        Storage::disk('private')->assertMissing($kept->path);
    }

    public function test_a_subgroup_that_a_task_uses_is_kept_with_every_file(): void
    {
        $file = $this->file($this->remote, 'part.dxf');
        $order = $this->in(fn () => Order::create(['name' => 'Gate order', 'description' => 'Keep', 'user_id' => $this->client->id,
            'creator_id' => $this->engineer->id, 'status' => 'pending', 'remote_number_id' => $this->remote->id]));
        // The number comes from the company's sequence, whatever is passed.
        $number = $this->in(fn () => $order->orderNumber()->create(['number' => 'next'])->number);
        $this->assertNotEmpty($number);
        $this->as($this->engineer);
        $this->getJson($this->url($this->remote).'/deletion', $this->headers())->assertOk()
            ->assertJson(['files' => 1, 'tasks_total' => 1, 'can_delete' => false])
            ->assertJsonPath('tasks.0.number', $number)->assertJsonPath('tasks.0.name', 'Gate order');
        $message = $this->deleteJson($this->url($this->remote), [], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('project')->json('errors.project.0');
        $this->assertStringContainsString('987.01', $message);
        $this->assertStringContainsString($number, $message);

        // A task of another project that only picked one of the files holds it too.
        $order->update(['remote_number_id' => null]);
        $this->in(fn () => SelectedFile::create(['order_id' => $order->id, 'pmp_file_id' => $file->id, 'quantity' => 1]));
        $this->deleteJson($this->url($this->remote), [], $this->headers())->assertUnprocessable();
        $this->deleteJson('/api/engineers/pmps/'.$this->pmp->id, [], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('project');

        $this->assertDatabaseHas('remote_numbers', ['id' => $this->remote->id]);
        $this->assertDatabaseHas('selected_files', ['order_id' => $order->id, 'pmp_file_id' => $file->id]);
        Storage::disk('private')->assertExists($file->path);
    }

    public function test_deleting_a_whole_unused_group_also_clears_its_stored_files(): void
    {
        $left = $this->file($this->remote, 'left.dxf');
        $right = $this->file($this->subgroup('02', 'Right gate'), 'right.dxf');
        $this->as($this->engineer);
        $this->deleteJson('/api/engineers/pmps/'.$this->pmp->id, [], $this->headers())->assertOk();
        $this->assertDatabaseMissing('pmps', ['id' => $this->pmp->id]);
        $this->assertDatabaseMissing('remote_numbers', ['pmp_id' => $this->pmp->id]);
        $this->assertDatabaseMissing('pmp_files', ['pmp_id' => $this->pmp->id]);
        Storage::disk('private')->assertMissing($left->path);
        Storage::disk('private')->assertMissing($right->path);
    }

    private function url(RemoteNumber $remote): string { return '/api/engineers/pmps/remote-number/'.$remote->id; }
    private function subgroup(string $code, string $name): RemoteNumber { return $this->in(fn () => RemoteNumber::create(['pmp_id' => $this->pmp->id, 'remote_number' => $code, 'remote_number_name' => $name])); }
    private function file(RemoteNumber $remote, string $name): PmpFiles
    {
        $path = 'companies/'.$this->company->id.'/PMP_'.$this->pmp->group.'.'.$remote->remote_number.'/DXF/'.uniqid().'.dxf';
        Storage::disk('private')->put($path, '0');
        return $this->in(fn () => PmpFiles::create(['pmp_id' => $remote->pmp_id, 'remote_number_id' => $remote->id, 'factory_id' => $this->factories['DXF']->id,
            'path' => $path, 'original_name' => $name, 'quantity' => 1, 'material_type' => 'Steel', 'thickness' => '2']));
    }
    private function in(callable $callback): mixed { return app(CompanyContext::class)->run($this->company, $callback); }
    private function account(string $role, array $permissions = []): User { return $this->in(function () use ($role, $permissions) { $user = User::create(['name' => $role, 'email' => $role.'-'.uniqid().'@example.invalid', 'password' => 'test-password', 'role_id' => Role::where('name', $role)->value('id')]); $m = CompanyMembership::where('user_id', $user->id)->first(); $m->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all()); return $user; }); }
    private function as(User $user): void { app(CompanyContext::class)->set(null); $user->unsetRelation('role')->unsetRelation('factory'); Sanctum::actingAs($user); }
    private function headers(): array { return ['Accept' => 'application/json', 'X-Company-ID' => (string) $this->company->id]; }
}
