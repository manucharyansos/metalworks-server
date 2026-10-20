<?php

namespace Tests\Feature;

use App\Models\{Company, CompanyMembership, Factory, FileCategory, FactoryOrder, Order, Permission, Pmp, PmpFiles, RemoteNumber, Role, User};
use App\Support\{CompanyContext, MembershipAssignments};
use Database\Seeders\{FactorySeeder, FactoryFileExtensionSeeder, FactoryOrderStatusSeeder, RoleTableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Mail, Schema, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyWorkshopCatalogTest extends TestCase
{
    use RefreshDatabase;
    private Company $company;
    private Company $other;
    private User $manager;
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
        $this->manager = $this->account('manager'); $this->engineer = $this->account('engineer'); $this->client = $this->account('authenticatedUser');
        $this->pmp = $this->in(fn () => Pmp::create(['group' => '987', 'group_name' => 'Catalog QA', 'admin_confirmation' => false]));
        $this->remote = $this->in(fn () => RemoteNumber::create(['pmp_id' => $this->pmp->id, 'remote_number' => '01', 'remote_number_name' => 'Catalog part']));
        Storage::fake('private'); Mail::fake();
    }

    public function test_real_workshops_and_file_sections_are_separate_and_powder_coating_is_available(): void
    {
        $this->as($this->manager);
        $workshops = $this->getJson('/api/workshops', $this->headers())->assertOk()->json('workshops');
        $this->assertContains('POWDER', array_column($workshops, 'value'));
        foreach (['SW', 'INFO', 'PDF'] as $code) $this->assertNotContains($code, array_column($workshops, 'value'));
        $sections = $this->getJson('/api/file-categories', $this->headers())->assertOk()->assertJsonCount(6, 'categories')->json('categories');
        $this->assertContains('SW', array_column($sections, 'code')); $this->assertContains('INFO', array_column($sections, 'code'));
        $this->assertNotContains('POWDER', array_column($sections, 'code'));
        $this->getJson('/api/staff/worker-options', $this->headers())->assertOk()->assertJsonCount(4, 'factories');
        $this->as($this->engineer);
        $this->getJson('/api/factories/factory', $this->headers())->assertOk()->assertJsonCount(4);
        $this->postJson('/api/workshops', ['name' => 'Welding'], $this->headers())->assertForbidden();
    }

    public function test_managers_and_admins_manage_workshops_and_company_boundaries_cannot_be_bypassed(): void
    {
        $this->as($this->manager);
        $created = $this->postJson('/api/workshops', ['name' => 'Welding'], $this->headers())->assertCreated()->assertJsonPath('workshop.is_workshop', true)->assertJsonPath('workshop.is_active', true)->json('workshop');
        $this->putJson('/api/workshops/'.$created['id'], ['name' => 'Assembly'], $this->headers())->assertOk()->assertJsonPath('workshop.name', 'Assembly');
        $this->postJson('/api/workshops', ['name' => 'Assembly'], $this->headers())->assertUnprocessable();
        $foreign = app(CompanyContext::class)->run($this->other, fn () => Factory::activeWorkshops()->first());
        $this->putJson('/api/workshops/'.$foreign->id, ['name' => 'Stolen'], $this->headers())->assertNotFound();
        $this->getJson('/api/workshops', ['X-Company-ID' => $this->other->id])->assertForbidden();
        $admin = $this->account('admin'); $this->as($admin);
        $this->putJson('/api/workshops/'.$created['id'], ['name' => 'Assembly', 'is_active' => false], $this->headers())->assertOk()->assertJsonPath('workshop.is_active', false);
        $this->putJson('/api/workshops/'.$created['id'], ['name' => 'Assembly', 'is_active' => true], $this->headers())->assertOk()->assertJsonPath('workshop.is_active', true);
        $this->deleteJson('/api/factories/factory/'.$created['id'], [], $this->headers())->assertUnprocessable();
        $this->assertDatabaseHas('factories', ['id' => $created['id'], 'name' => 'Assembly']);
        $this->putJson('/api/workshops/'.$this->factories['INFO']->id, ['name' => 'Info workshop'], $this->headers())->assertNotFound();
    }

    public function test_archiving_requires_open_jobs_and_staff_to_be_transferred_and_keeps_files_and_history(): void
    {
        $factory = $this->in(fn () => Factory::create(['name' => 'Finishing', 'value' => 'FINISH']));
        $operator = $this->account('operator', $factory->id);
        $this->as($this->manager);
        $body = ['name' => 'Finishing', 'is_active' => false];
        $this->putJson('/api/workshops/'.$factory->id, $body, $this->headers())->assertUnprocessable();
        $this->in(fn () => MembershipAssignments::sync(CompanyMembership::where('user_id', $operator->id)->first(), [['role_id' => Role::where('name', 'engineer')->value('id'), 'factory_id' => null]]));
        $order = $this->in(fn () => Order::create(['name' => 'History', 'description' => 'Keep', 'user_id' => $this->client->id, 'creator_id' => $this->engineer->id, 'status' => 'pending']));
        $step = $this->in(fn () => FactoryOrder::create(['order_id' => $order->id, 'factory_id' => $factory->id, 'status' => 'pending']));
        $this->putJson('/api/workshops/'.$factory->id, $body, $this->headers())->assertUnprocessable();
        $this->in(fn () => $step->update(['status' => 'finished', 'completed_at' => now()]));
        $this->putJson('/api/workshops/'.$factory->id, $body, $this->headers())->assertOk();
        $this->assertDatabaseHas('factory_orders', ['id' => $step->id, 'factory_id' => $factory->id]);
        $this->as($this->engineer); $this->getJson('/api/factories/factory', $this->headers())->assertOk()->assertJsonMissing(['id' => $factory->id]);
    }

    public function test_custom_file_section_upload_and_one_drawing_can_create_powder_and_future_workshop_jobs(): void
    {
        $this->as($this->manager);
        $workshop = $this->postJson('/api/workshops', ['name' => 'Welding'], $this->headers())->assertCreated()->json('workshop');
        $category = $this->postJson('/api/file-categories', ['name' => 'Production drawings', 'is_reference' => false, 'default_factory_id' => $workshop['id'], 'extensions' => ['.DXF', 'pdf']], $this->headers())->assertCreated()->json('category');
        $this->assertFalse($this->in(fn () => Factory::find($category['legacy_factory_id'])->is_workshop));
        $this->as($this->engineer);
        $drawing = $this->post('/api/engineers/uploadPmpFile', ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'file_category_id' => $category['id'], 'file' => UploadedFile::fake()->create('panel.dxf', 2)], $this->headers())->assertCreated()->assertJsonPath('file.file_category_id', $category['id'])->assertJsonPath('file.routing_factory_id', $workshop['id'])->json('file');
        $info = $this->file($this->factories['INFO']->id, 'note.txt');
        $powder = $this->factories['POWDER']->id;
        $order = $this->postJson('/api/engineers/engineer', $this->payload([$drawing['id'], $info->id]) + ['file_routing' => [['file_id' => $drawing['id'], 'factory_ids' => [$workshop['id'], $powder]]], 'reference_file_visibility' => [['file_id' => $info->id, 'factory_ids' => [$powder]]]], $this->headers())->assertCreated()->assertJsonCount(2, 'order.factory_orders')->json('order');
        $jobs = collect($order['factory_orders'])->keyBy('factory_id');
        $this->assertCount(1, $jobs[$workshop['id']]['files']); $this->assertCount(2, $jobs[$powder]['files']);
        $this->assertNotContains($category['legacy_factory_id'], $jobs->keys()->all());
        $this->assertNotContains($this->factories['INFO']->id, $jobs->keys()->all());
        // An unstarted edit retains the explicit destinations, not the file section's old default.
        $this->putJson('/api/engineers/engineer/'.$order['id'], $this->payload([$drawing['id'], $info->id]), $this->headers())->assertOk()->assertJsonCount(2, 'order.factory_orders');
    }

    public function test_reference_sections_cannot_be_workshops_and_explicit_routing_rejects_private_or_archived_targets(): void
    {
        $file = $this->file($this->factories['DXF']->id, 'part.dxf');
        $this->as($this->manager);
        $foreign = app(CompanyContext::class)->run($this->other, fn () => Factory::activeWorkshops()->first());
        $archived = $this->in(fn () => Factory::create(['name' => 'Old workshop', 'value' => 'OLD', 'is_active' => false]));
        $this->as($this->engineer);
        foreach ([$this->factories['SW']->id, $this->factories['INFO']->id, $foreign->id, $archived->id] as $id) $this->postJson('/api/engineers/engineer', $this->payload([$file->id]) + ['file_routing' => [['file_id' => $file->id, 'factory_ids' => [$id]]]], $this->headers())->assertUnprocessable();
        $info = $this->file($this->factories['INFO']->id, 'instructions.txt');
        $this->postJson('/api/engineers/engineer', $this->payload([$info->id]), $this->headers())->assertUnprocessable();
        $this->postJson('/api/file-categories', ['name' => 'Unauthorized', 'is_reference' => true, 'extensions' => []], $this->headers())->assertForbidden();
        $this->as($this->manager);
        $this->postJson('/api/file-categories', ['name' => 'Private', 'is_reference' => false, 'default_factory_id' => $foreign->id, 'extensions' => ['pdf']], $this->headers())->assertUnprocessable();
    }

    public function test_generic_operator_works_in_selected_workshop_and_sees_only_its_files(): void
    {
        $factory = $this->in(fn () => Factory::create(['name' => 'Assembly', 'value' => 'ASSEMBLY']));
        $operator = $this->account('operator', $factory->id);
        $file = $this->file($this->factories['DXF']->id, 'shared.dxf'); $info = $this->file($this->factories['INFO']->id, 'private-note.txt');
        $this->as($this->engineer);
        $order = $this->postJson('/api/engineers/engineer', $this->payload([$file->id, $info->id]) + ['file_routing' => [['file_id' => $file->id, 'factory_ids' => [$factory->id]]], 'factory_operators' => [['factory_id' => $factory->id, 'user_id' => $operator->id]]], $this->headers())->assertCreated()->json('order');
        $this->as($operator);
        $this->getJson('/api/factories/factory', $this->headers())->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $factory->id);
        $this->getJson('/api/factories/factory/'.$factory->id, $this->headers())->assertOk()->assertJsonCount(1, 'orders.0.factory_orders.0.files');
        $this->get('/api/secure-files/pmp/'.$file->id, $this->headers())->assertOk();
        $this->get('/api/secure-files/pmp/'.$info->id, $this->headers())->assertForbidden();
        $this->putJson('/api/factories/updateOrder/'.$order['id'], ['factory_id' => $factory->id, 'factory_order' => ['status' => 'accepted']], $this->headers())->assertOk();
        $this->putJson('/api/factories/updateOrder/'.$order['id'], ['factory_id' => $factory->id, 'factory_order' => ['status' => 'finished']], $this->headers())->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_migration_retry_preserves_file_bytes_custom_categories_and_existing_job_ids(): void
    {
        $file = $this->file($this->factories['SW']->id, 'old.sldprt');
        $category = $this->in(fn () => FileCategory::where('legacy_factory_id', $this->factories['SW']->id)->first());
        $this->in(fn () => $category->update(['name' => 'Our custom CAD section']));
        DB::table('pmp_files')->where('id', $file->id)->update(['file_category_id' => null]);
        $reference = $this->in(fn () => Factory::create(['name' => 'Legacy reference section', 'value' => 'OLD_INFO', 'is_reference' => true]));
        $migration = require database_path('migrations/2026_10_19_120000_separate_workshops_and_file_categories.php'); $migration->up(); $migration->up();
        $this->assertDatabaseHas('factories', ['id' => $reference->id, 'is_workshop' => false]);
        $this->assertDatabaseHas('pmp_files', ['id' => $file->id, 'file_category_id' => $category->id, 'path' => $file->path]);
        $this->assertDatabaseHas('file_categories', ['id' => $category->id, 'name' => 'Our custom CAD section']);
        $this->assertSame('private bytes', Storage::disk('private')->get($file->path));
        $this->assertSame(1, DB::table('factories')->where('company_id', $this->company->id)->where('value', 'POWDER')->count());
    }

    public function test_generic_operator_can_switch_two_workshops_without_seeing_the_other_workshops_files(): void
    {
        $powder = $this->factories['POWDER']; $laser = $this->factories['DXF'];
        $operator = $this->account('operator', $laser->id);
        $this->in(function () use ($operator, $laser, $powder) {
            MembershipAssignments::sync(CompanyMembership::where('user_id', $operator->id)->first(), [
                ['role_id' => Role::where('name', 'operator')->value('id'), 'factory_id' => $laser->id],
                ['role_id' => Role::where('name', 'operator')->value('id'), 'factory_id' => $powder->id],
            ]);
        });
        $file = $this->file($laser->id, 'two-workshops.dxf'); $reference = $this->file($this->factories['INFO']->id, 'powder-only.txt');
        $this->as($this->engineer);
        $this->postJson('/api/engineers/engineer', $this->payload([$file->id, $reference->id]) + ['file_routing' => [['file_id' => $file->id, 'factory_ids' => [$laser->id, $powder->id]]], 'reference_file_visibility' => [['file_id' => $reference->id, 'factory_ids' => [$powder->id]]], 'factory_operators' => [['factory_id' => $laser->id, 'user_id' => $operator->id], ['factory_id' => $powder->id, 'user_id' => $operator->id]]], $this->headers())->assertCreated();
        $this->as($operator);
        $this->getJson('/api/user', $this->headers())->assertOk()->assertJsonCount(2, 'assignments')->assertJsonPath('role.name', 'operator');
        $this->get('/api/secure-files/pmp/'.$reference->id, $this->headers())->assertForbidden();
        $assignment = CompanyMembership::where('user_id', $operator->id)->first()->assignments()->where('factory_id', $powder->id)->value('id');
        $headers = $this->headers() + ['X-Assignment-ID' => (string) $assignment];
        $this->getJson('/api/factories/factory', $headers)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $powder->id);
        $this->get('/api/secure-files/pmp/'.$reference->id, $headers)->assertOk();
        $this->getJson('/api/factories/factory/'.$laser->id, $headers)->assertForbidden();
    }

    public function test_categories_archive_without_losing_files_and_an_explicitly_cleared_default_stays_cleared(): void
    {
        $file = $this->file($this->factories['DXF']->id, 'retained.dxf');
        $category = $this->in(fn () => $file->fresh()->category);
        $this->as($this->manager);
        $body = ['name' => 'Drawings', 'is_reference' => false, 'default_factory_id' => null, 'extensions' => ['dxf'], 'is_active' => true];
        $this->putJson('/api/file-categories/'.$category->id, $body, $this->headers())->assertOk();
        $this->assertNull($this->in(fn () => $file->fresh()->routing_factory_id));
        $body['is_active'] = false;
        $this->putJson('/api/file-categories/'.$category->id, $body, $this->headers())->assertOk();
        $this->as($this->engineer);
        $this->post('/api/engineers/uploadPmpFile', ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'file_category_id' => $category->id, 'file' => UploadedFile::fake()->create('new.dxf', 1)], $this->headers())->assertUnprocessable();
        $this->getJson('/api/file-categories?include_archived=1', $this->headers())->assertOk()->assertJsonFragment(['id' => $category->id, 'is_active' => false]);
        $this->get('/api/secure-files/pmp/'.$file->id, $this->headers())->assertOk();
        $this->assertSame('private bytes', Storage::disk('private')->get($file->path));
    }

    public function test_cad_and_pdf_drawings_can_be_sent_to_powder_without_creating_fake_workshops(): void
    {
        $cad = $this->file($this->factories['SW']->id, 'assembly.sldprt');
        $this->as($this->engineer);
        $this->postJson('/api/engineers/engineer', $this->payload([$cad->id]), $this->headers())->assertUnprocessable();
        $this->postJson('/api/engineers/engineer', $this->payload([$cad->id]) + ['file_routing' => [['file_id' => $cad->id, 'factory_ids' => [$this->factories['POWDER']->id]]]], $this->headers())->assertCreated()->assertJsonCount(1, 'order.factory_orders')->assertJsonPath('order.factory_orders.0.factory_id', $this->factories['POWDER']->id);
        $pdf = $this->file($this->factories['PDF']->id, 'drawing.pdf'); $category = $this->in(fn () => $pdf->fresh()->category);
        $this->as($this->manager);
        $this->putJson('/api/file-categories/'.$category->id, ['name' => 'PDF drawings', 'is_reference' => false, 'default_factory_id' => $this->factories['POWDER']->id, 'extensions' => ['pdf']], $this->headers())->assertOk();
        $this->as($this->engineer);
        $this->postJson('/api/engineers/engineer', $this->payload([$pdf->id]), $this->headers())->assertCreated()->assertJsonCount(1, 'order.factory_orders')->assertJsonPath('order.factory_orders.0.factory_id', $this->factories['POWDER']->id);
    }

    private function file(int $factory, string $name): PmpFiles { return $this->in(function () use ($factory, $name) { $path = 'companies/'.$this->company->id.'/qa/'.$name; Storage::disk('private')->put($path, 'private bytes'); return PmpFiles::create(['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'factory_id' => $factory, 'original_name' => $name, 'path' => $path]); }); }
    public function test_legacy_non_workshop_steps_do_not_block_real_production_completion(): void
    {
        $laser = $this->factories['DXF']; $operator = $this->account('operator', $laser->id);
        $drawing = $this->file($laser->id, 'current.dxf'); $this->as($this->engineer);
        $order = $this->postJson('/api/engineers/engineer', $this->payload([$drawing->id]) + ['factory_operators' => [['factory_id' => $laser->id, 'user_id' => $operator->id]]], $this->headers())->assertCreated()->json('order');
        $legacy = $this->in(fn () => FactoryOrder::create(['order_id' => $order['id'], 'factory_id' => $this->factories['SW']->id, 'status' => 'pending']));
        $this->as($operator);
        $this->putJson('/api/factories/updateOrder/'.$order['id'], ['factory_id' => $laser->id, 'factory_order' => ['status' => 'finished']], $this->headers())->assertOk()->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('factory_orders', ['id' => $legacy->id, 'status' => 'pending']);
    }

    public function test_adding_later_work_uses_canonical_drawing_and_reference_categories(): void
    {
        $pdf = $this->file($this->factories['PDF']->id, 'drawing.pdf'); $info = $this->file($this->factories['INFO']->id, 'notes.txt');
        $this->in(fn () => $pdf->category->update(['is_reference' => false, 'default_factory_id' => $this->factories['DXF']->id]));
        $this->as($this->engineer);
        $order = $this->postJson('/api/engineers/engineer', $this->payload([$pdf->id, $info->id]), $this->headers())->assertCreated()->json('order');
        $result = $this->postJson('/api/tasks/'.$order['id'].'/workshops', ['factory_id' => $this->factories['POWDER']->id, 'files' => [['id' => $pdf->id, 'quantity' => 1], ['id' => $info->id, 'quantity' => 1]]], $this->headers())->assertCreated();
        $shares = $this->in(fn () => Order::find($order['id'])->reference_file_visibility);
        $this->assertSame([$info->id], array_column($shares, 'file_id'));
        $this->assertSame([$this->factories['POWDER']->id], $shares[0]['factory_ids']);
    }


    public function test_legacy_engineering_and_info_steps_never_enter_admin_counts_task_details_or_engineer_decisions(): void
    {
        $note = $this->file($this->factories['INFO']->id, 'preserved-note.txt');
        [$order, $real, $legacy] = $this->in(function () use ($note) {
            DB::table('factories')->whereIn('id', [$this->factories['SW']->id, $this->factories['INFO']->id])->update(['is_workshop' => true, 'is_reference' => false]);
            $order = Order::create(['name' => 'Legacy', 'description' => 'Preserve files', 'user_id' => $this->client->id, 'creator_id' => $this->engineer->id, 'status' => 'pending']);
            $real = FactoryOrder::create(['order_id' => $order->id, 'factory_id' => $this->factories['DXF']->id, 'status' => 'confirmed']);
            $real->files()->attach($note->id, ['quantity' => 1]);
            $legacy = FactoryOrder::create(['order_id' => $order->id, 'factory_id' => $this->factories['SW']->id, 'status' => 'finished', 'confirmation_required' => true, 'confirmation_method' => 'text']);
            $real->update(['depends_on_id' => $legacy->id]);
            $this->assertFalse($real->fresh()->is_blocked);
            FactoryOrder::create(['order_id' => $order->id, 'factory_id' => $this->factories['INFO']->id, 'status' => 'pending']);
            DB::table('company_memberships')->where('user_id', $this->engineer->id)->update(['factory_id' => $this->factories['SW']->id]);
            DB::table('membership_assignments')->where('user_id', $this->engineer->id)->update(['factory_id' => $this->factories['SW']->id]);
            return [$order, $real, $legacy];
        });
        $this->as($this->account('admin'));
        $dashboard = $this->getJson('/api/admin/dashboard', $this->headers())->assertOk();
        $dashboard->assertJsonPath('summary.factories', 4)->assertJsonPath('summary.factory_operators', 0)->assertJsonPath('summary.unassigned_factory_steps', 1)->assertJsonPath('summary.awaiting_engineer_confirmation', 0);
        $this->assertNotContains('SW', array_column($dashboard->json('filters.factories'), 'value'));
        $this->getJson('/api/admin/order/'.$order->id, $this->headers())->assertOk()->assertJsonCount(1, 'factory_orders')->assertJsonPath('factory_orders.0.id', $real->id)->assertJsonPath('factory_orders.0.files.0.id', $note->id);
        $this->getJson('/api/admin/dashboard/orders', $this->headers())->assertOk()->assertJsonCount(1, 'orders.0.factory_orders');
        $this->as($this->engineer);
        $this->getJson('/api/engineers/engineer?stage=in_progress', $this->headers())->assertOk()->assertJsonPath('stage_counts.in_progress', 1)->assertJsonPath('stage_counts.completed', 0)->assertJsonCount(1, 'orders.0.factory_orders');
        $this->postJson('/api/engineers/factory-orders/'.$legacy->id.'/confirm', [], $this->headers())->assertNotFound();
        $this->assertDatabaseHas('factory_orders', ['id' => $legacy->id, 'status' => 'finished']);
        $this->assertSame('private bytes', Storage::disk('private')->get($note->path));
    }

    public function test_role_metadata_repair_is_idempotent_and_preserves_assignments_files_and_company_boundaries(): void
    {
        $file = $this->file($this->factories['SW']->id, 'engineering.sldprt');
        $before = DB::table('membership_assignments')->where('user_id', $this->engineer->id)->pluck('id')->all();
        DB::table('membership_assignments')->whereIn('id', $before)->update(['factory_id' => $this->factories['SW']->id]);
        DB::table('company_memberships')->where('user_id', $this->engineer->id)->update(['factory_id' => $this->factories['SW']->id]);
        DB::table('users')->where('id', $this->engineer->id)->update(['factory_id' => $this->factories['SW']->id]);
        $operator = $this->account('operator', $this->factories['POWDER']->id);
        $order = $this->in(function () use ($file, $operator) {
            $order = Order::create(['name' => 'Legacy attachments', 'user_id' => $this->client->id, 'creator_id' => $this->engineer->id, 'status' => 'pending']);
            $legacy = FactoryOrder::create(['order_id' => $order->id, 'factory_id' => $this->factories['SW']->id, 'status' => 'pending']);
            $legacy->files()->attach($file->id, ['quantity' => 2]);
            FactoryOrder::create(['order_id' => $order->id, 'factory_id' => $this->factories['POWDER']->id, 'operator_id' => $operator->id, 'status' => 'pending', 'depends_on_id' => $legacy->id]);
            return $order;
        });
        $migration = require database_path('migrations/2026_10_19_190000_normalize_non_production_workshop_links.php');
        $migration->up(); $migration->up();
        $this->assertSame($before, DB::table('membership_assignments')->where('user_id', $this->engineer->id)->pluck('id')->all());
        $this->assertSame(0, DB::table('membership_assignments')->where('user_id', $this->engineer->id)->whereNotNull('factory_id')->count());
        $this->assertDatabaseHas('users', ['id' => $this->engineer->id, 'factory_id' => null]);
        $this->assertDatabaseHas('company_memberships', ['user_id' => $this->engineer->id, 'company_id' => $this->company->id, 'factory_id' => null]);
        $this->assertDatabaseHas('membership_assignments', ['user_id' => $operator->id, 'factory_id' => $this->factories['POWDER']->id]);
        $this->assertDatabaseHas('pmp_files', ['id' => $file->id]);
        $this->assertSame(1, DB::table('selected_files')->where('order_id', $order->id)->where('pmp_file_id', $file->id)->count());
        $this->assertDatabaseHas('selected_files', ['order_id' => $order->id, 'pmp_file_id' => $file->id, 'company_id' => $this->company->id, 'quantity' => 2]);
        $this->assertDatabaseHas('factory_orders', ['order_id' => $order->id, 'factory_id' => $this->factories['POWDER']->id, 'depends_on_id' => null]);
        $this->as($this->engineer);
        $this->getJson('/api/engineers/engineer/'.$order->id, $this->headers())->assertOk()->assertJsonCount(1, 'order.factory_orders')->assertJsonPath('order.selected_files.0.pmp_file.id', $file->id);
        $this->getJson('/api/user', $this->headers())->assertOk()->assertJsonPath('factory_id', null);
        $this->get('/api/secure-files/pmp/'.$file->id, $this->headers())->assertOk();
        $this->getJson('/api/engineers/engineer', ['X-Company-ID' => $this->other->id])->assertForbidden();
        $this->as($operator);
        $this->getJson('/api/factories/factory/'.$this->factories['POWDER']->id, $this->headers())->assertOk()->assertJsonMissingPath('orders.0.selected_files')->assertJsonPath('orders.0.id', $order->id)->assertJsonCount(0, 'orders.0.factory_orders.0.files');
        $this->get('/api/secure-files/pmp/'.$file->id, $this->headers())->assertForbidden();
    }

    public function test_legacy_order_endpoints_reject_file_sections_and_retain_archived_workshop_history(): void
    {
        $this->as($this->account('admin'));
        foreach (['SW', 'INFO', 'PDF'] as $code) {
            $this->postJson('/api/admin/order', ['user_id' => $this->client->id, 'name' => 'Invalid', 'description' => 'Invalid production', 'factories' => [['id' => $this->factories[$code]->id]]], $this->headers())->assertUnprocessable();
        }
        $order = $this->in(function () {
            $order = Order::create(['user_id' => $this->client->id, 'creator_id' => $this->engineer->id, 'name' => 'History', 'description' => 'Keep', 'status' => 'completed']);
            $order->factoryOrders()->create(['factory_id' => $this->factories['POWDER']->id, 'status' => 'finished', 'completed_at' => now()]);
            $this->factories['POWDER']->update(['is_active' => false]);
            return $order;
        });
        $this->putJson('/api/admin/order/'.$order->id, ['name' => 'Retained history', 'description' => 'Keep files', 'factories' => [['id' => $this->factories['POWDER']->id]], 'status' => 'completed'], $this->headers())->assertOk();
        $this->getJson('/api/admin/order/'.$order->id, $this->headers())->assertOk()->assertJsonCount(1, 'factory_orders')->assertJsonPath('factory_orders.0.factory.value', 'POWDER');
    }

    public function test_dashboard_counts_pending_tasks_once_and_separates_confirmed_completions_from_both_cancellation_spellings(): void
    {
        $review = $this->in(function () {
            $order = Order::create(['name' => 'Two proofs, one task', 'user_id' => $this->client->id, 'creator_id' => $this->engineer->id, 'status' => 'pending']);
            foreach (['DXF', 'POWDER'] as $code) $order->factoryOrders()->create(['factory_id' => $this->factories[$code]->id, 'status' => 'finished', 'confirmation_required' => true, 'confirmation_method' => 'text']);
            foreach (['completed', 'canceled', 'cancelled'] as $status) Order::create(['name' => $status, 'user_id' => $this->client->id, 'creator_id' => $this->engineer->id, 'status' => $status]);
            return $order;
        });
        $this->as($this->account('admin'));
        $this->getJson('/api/admin/dashboard', $this->headers())->assertOk()->assertJsonPath('summary.total_orders', 4)->assertJsonPath('summary.awaiting_engineer_confirmation', 1)->assertJsonPath('summary.completed_orders', 1)->assertJsonPath('summary.canceled_orders', 2);
        $this->getJson('/api/admin/dashboard/orders?confirmation=waiting', $this->headers())->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('orders.0.id', $review->id);
        $this->getJson('/api/admin/dashboard/orders?order_status=completed', $this->headers())->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('orders.0.status', 'completed');
        foreach (['canceled', 'cancelled'] as $status) $this->getJson('/api/admin/dashboard/orders?order_status='.$status, $this->headers())->assertOk()->assertJsonPath('pagination.total', 2);
    }

    public function test_workload_task_list_requires_open_work_for_the_operator_in_the_same_workshop(): void
    {
        $operator = $this->account('operator', $this->factories['DXF']->id);
        $otherOperator = $this->account('operator', $this->factories['POWDER']->id);
        $open = $this->in(function () use ($operator, $otherOperator) {
            $order = Order::create(['name' => 'Open work', 'user_id' => $this->client->id, 'creator_id' => $this->engineer->id, 'status' => 'pending']);
            $order->factoryOrders()->create(['factory_id' => $this->factories['DXF']->id, 'operator_id' => $operator->id, 'status' => 'confirmed']);
            $order->factoryOrders()->create(['factory_id' => $this->factories['POWDER']->id, 'operator_id' => $otherOperator->id, 'status' => 'pending']);
            foreach (['finished', 'canceled'] as $status) {
                $closed = Order::create(['name' => 'Closed work '.$status, 'user_id' => $this->client->id, 'creator_id' => $this->engineer->id, 'status' => $status === 'finished' ? 'completed' : 'canceled']);
                $closed->factoryOrders()->create(['factory_id' => $this->factories['DXF']->id, 'operator_id' => $operator->id, 'status' => $status, 'completed_at' => $status === 'finished' ? now() : null]);
            }
            return $order;
        });
        $this->as($this->account('admin'));
        $url = '/api/admin/dashboard/orders?work_state=open&operator_id='.$operator->id;
        $this->getJson($url, $this->headers())->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('orders.0.id', $open->id);
        $this->getJson($url.'&factory_id='.$this->factories['POWDER']->id, $this->headers())->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson($url, ['X-Company-ID' => $this->other->id])->assertForbidden();
    }

    public function test_overdue_list_is_paginated_beyond_the_attention_preview_and_includes_unset_status(): void
    {
        $this->in(function () {
            for ($i = 0; $i < 13; $i++) {
                $order = Order::create(['name' => 'Overdue '.$i, 'user_id' => $this->client->id, 'creator_id' => $this->engineer->id, 'status' => $i === 0 ? '' : 'pending']);
                $order->dates()->create(['finish_date' => now()->subDays(3)]);
            }
            foreach (['completed', 'canceled'] as $status) {
                $order = Order::create(['name' => 'Not overdue '.$status, 'user_id' => $this->client->id, 'creator_id' => $this->engineer->id, 'status' => $status]);
                $order->dates()->create(['finish_date' => now()->subDays(3)]);
            }
        });
        $this->as($this->account('admin'));
        $this->getJson('/api/admin/dashboard', $this->headers())->assertOk()->assertJsonPath('summary.overdue_orders', 13);
        $first = $this->getJson('/api/admin/dashboard/orders?time_range=overdue&per_page=12', $this->headers())->assertOk()->assertJsonCount(12, 'orders')->assertJsonPath('pagination.total', 13)->assertJsonPath('pagination.last_page', 2);
        $second = $this->getJson('/api/admin/dashboard/orders?time_range=overdue&per_page=12&page=2', $this->headers())->assertOk()->assertJsonCount(1, 'orders')->assertJsonPath('pagination.total', 13);
        $this->assertNotContains($second->json('orders.0.id'), array_column($first->json('orders'), 'id'));
    }

    public function test_general_attachment_formats_bind_the_correct_record_and_remain_private_per_company(): void
    {
        $foreign = app(CompanyContext::class)->run($this->other, fn () => \App\Models\FileExtension::create(['extension' => 'dxf']));
        $this->as($this->account('admin'));
        $created = $this->postJson('/api/admin/file-extensions', ['extension' => '.PDF'], $this->headers())->assertCreated()->assertJsonPath('data.extension', 'pdf')->json('data');
        $id = $created['id'];
        $this->putJson('/api/admin/file-extensions/'.$id, ['extension' => '.DXF'], $this->headers())->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.extension', 'dxf');
        $this->assertDatabaseHas('file_extensions', ['id' => $id, 'company_id' => $this->company->id, 'extension' => 'dxf']);
        $this->putJson('/api/admin/file-extensions/'.$id, ['extension' => 'dxf'], $this->headers())->assertOk();
        $this->postJson('/api/admin/file-extensions', ['extension' => 'DXF'], $this->headers())->assertUnprocessable();
        $this->postJson('/api/admin/file-extensions', ['extension' => '../bad'], $this->headers())->assertUnprocessable();
        $this->getJson('/api/admin/file-extensions/'.$id, $this->headers())->assertOk()->assertJsonPath('id', $id);
        $this->putJson('/api/admin/file-extensions/'.$foreign->id, ['extension' => 'txt'], $this->headers())->assertNotFound();
        $this->deleteJson('/api/admin/file-extensions/'.$foreign->id, [], $this->headers())->assertNotFound();
        $this->deleteJson('/api/admin/file-extensions/'.$id, [], $this->headers())->assertOk();
        $this->assertDatabaseMissing('file_extensions', ['id' => $id]);
        $this->assertDatabaseHas('file_extensions', ['id' => $foreign->id, 'company_id' => $this->other->id, 'extension' => 'dxf']);
    }

    private function payload(array $ids): array { return ['user_id' => $this->client->id, 'pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'name' => '987.01', 'description' => 'Catalog task', 'finish_date' => '2099-01-01', 'link_existing_files' => true, 'selected_files' => array_map(fn ($id) => ['id' => $id, 'quantity' => 1], $ids)]; }
    private function in(callable $callback): mixed { return app(CompanyContext::class)->run($this->company, $callback); }
    private function account(string $role, ?int $factory = null): User { return $this->in(function () use ($role, $factory) { $user = User::create(['name' => $role, 'email' => $role.'-'.uniqid().'@example.invalid', 'password' => 'test-password', 'role_id' => Role::where('name', $role)->value('id'), 'factory_id' => $factory]); $m = CompanyMembership::where('user_id', $user->id)->first(); $m->permissions()->sync(Permission::whereIn('slug', ['orders.view', 'orders.create', 'orders.update', 'factory.view', 'factory.download', 'factory.order_update', 'pmp.view', 'pmp_files.view', 'pmp_files.upload'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all()); return $user; }); }
    private function as(User $user): void { app(CompanyContext::class)->set(null); $user->unsetRelation('role')->unsetRelation('factory'); Sanctum::actingAs($user); }
    private function headers(): array { return ['Accept' => 'application/json', 'X-Company-ID' => (string) $this->company->id]; }
}
