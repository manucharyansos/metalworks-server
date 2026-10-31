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
        $this->getJson('/api/staff/worker-options', $this->headers())->assertOk()->assertJsonCount(6, 'factories');
        $this->as($this->engineer);
        $this->getJson('/api/factories/factory', $this->headers())->assertOk()->assertJsonCount(6);
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

    public function test_default_catalog_has_six_real_workshops_and_independent_file_sections(): void
    {
        $this->as($this->manager);
        $workshops = collect($this->getJson('/api/workshops', $this->headers())->assertOk()->json('workshops'))->keyBy('value');
        $expected = ['DXF' => 'Լազերային կտրում՝ լիստեր', 'IQS' => 'Լազերային կտրում՝ խողովակներ',
            'DLD' => 'Կռում', 'WELD' => 'Զոդում', 'ROLL' => 'Վալց', 'POWDER' => 'Փոշեներկում'];
        $this->assertCount(6, $workshops);
        foreach ($expected as $code => $name) {
            $this->assertSame($name, $workshops[$code]['name']);
            $this->assertTrue($workshops[$code]['is_workshop']);
        }
        $this->in(fn () => (new FactorySeeder)->run());
        $this->assertSame($workshops->pluck('id', 'value')->all(), collect($this->getJson('/api/workshops', $this->headers())->assertOk()->json('workshops'))->pluck('id', 'value')->all());
        $sections = collect($this->getJson('/api/file-categories', $this->headers())->assertOk()->assertJsonCount(6, 'categories')->json('categories'))->keyBy('code');
        $this->assertSame('IGS', $sections['IQS']['name']);
        $this->assertSame('DXF', $sections['DXF']['name']);
        $this->assertSame('INFO', $sections['INFO']['name']);
        $this->assertFalse($sections->has('WELD'));
        $this->assertFalse($sections->has('ROLL'));
    }

    public function test_default_file_sections_get_their_short_codes_back_and_custom_names_stay(): void
    {
        $sections = fn () => DB::table('file_categories')->where('company_id', $this->company->id)->orderBy('id');
        foreach (['SW' => 'CAD նախագծեր', 'DLD' => 'Կռման գծագրեր', 'DXF' => 'DXF գծագրեր', 'IQS' => '3D գծագրեր', 'PDF' => 'PDF փաստաթղթեր', 'INFO' => 'Our reference folder'] as $code => $name) {
            $sections()->where('code', $code)->update(['name' => $name]);
        }
        $ids = $sections()->pluck('id', 'code')->all();
        $migration = require database_path('migrations/2026_10_29_090000_restore_file_section_codes.php');
        $migration->up(); $migration->up();
        $names = $sections()->whereIn('code', ['SW', 'DLD', 'DXF', 'IQS', 'PDF', 'INFO'])->pluck('name', 'code')->all();
        ksort($names);
        $this->assertSame(['DLD' => 'DLD', 'DXF' => 'DXF', 'INFO' => 'Our reference folder', 'IQS' => 'IGS', 'PDF' => 'PDF', 'SW' => 'SW'], $names);
        $this->assertSame($ids, $sections()->pluck('id', 'code')->all());
    }

    public function test_catalog_upgrade_is_repeatable_keeps_workshop_ids_and_does_not_change_other_companies(): void
    {
        $file = $this->file($this->factories['DXF']->id, 'existing.dxf');
        $operator = $this->account('operator', $this->factories['DXF']->id);
        $this->as($this->engineer);
        $task = $this->postJson('/api/engineers/engineer', $this->payload([$file->id]), $this->headers())->assertCreated()->json('order');
        $this->in(function () {
            $this->factories['DXF']->update(['name' => 'Laser cutting']);
            $this->factories['IQS']->update(['name' => 'Laser']);
            $this->factories['DLD']->update(['name' => 'Bend']);
            $this->factories['POWDER']->update(['name' => 'Our custom coating name', 'is_active' => false]);
            Factory::whereIn('value', ['WELD', 'ROLL'])->delete();
        });
        $existing = $this->in(fn () => Factory::create(['name' => 'Welding', 'value' => 'WORK_EXISTING_WELD', 'is_active' => true]));
        $foreign = DB::table('factories')->where('company_id', $this->other->id)->orderBy('id')->get()->toJson();
        $categories = DB::table('file_categories')->where('company_id', $this->company->id)->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_27_120000_complete_metalworks_production_workshops.php');
        $migration->up(); $migration->up();
        $this->in(fn () => (new FactorySeeder)->run());
        $this->assertSame($foreign, DB::table('factories')->where('company_id', $this->other->id)->orderBy('id')->get()->toJson());
        $this->assertSame($categories, DB::table('file_categories')->where('company_id', $this->company->id)->orderBy('id')->get()->toJson());
        $this->assertSame(6, $this->in(fn () => Factory::workshops()->count()));
        foreach (['DXF' => 'Լազերային կտրում՝ լիստեր', 'IQS' => 'Լազերային կտրում՝ խողովակներ', 'DLD' => 'Կռում'] as $code => $name) {
            $this->assertDatabaseHas('factories', ['id' => $this->factories[$code]->id, 'company_id' => $this->company->id, 'name' => $name]);
        }
        $this->assertDatabaseHas('factories', ['id' => $existing->id, 'name' => 'Զոդում', 'value' => 'WORK_EXISTING_WELD']);
        $this->assertDatabaseHas('factories', ['id' => $this->factories['POWDER']->id, 'name' => 'Our custom coating name', 'is_active' => false]);
        $this->assertDatabaseHas('factory_orders', ['order_id' => $task['id'], 'factory_id' => $this->factories['DXF']->id]);
        $this->assertDatabaseHas('membership_assignments', ['user_id' => $operator->id, 'factory_id' => $this->factories['DXF']->id]);
        $this->assertDatabaseHas('pmp_files', ['id' => $file->id, 'factory_id' => $this->factories['DXF']->id]);
    }

    public function test_every_production_workshop_can_receive_files_and_its_operator_can_open_their_details(): void
    {
        $file = $this->file($this->factories['DXF']->id, 'all-processes.dxf');
        $path = array_map(fn ($code) => $this->factories[$code]->id, ['DXF', 'IQS', 'DLD', 'WELD', 'ROLL', 'POWDER']);
        $this->in(fn () => $file->update(['production_factory_ids' => $path, 'dimensions' => '250 × 500', 'inspection_required' => true]));
        $this->as($this->engineer);
        $order = $this->postJson('/api/engineers/engineer', $this->payload([$file->id]), $this->headers())->assertCreated()->assertJsonCount(6, 'order.factory_orders')->json('order');
        foreach ($path as $id) {
            $operator = $this->account('operator', $id);
            $this->as($operator);
            $this->getJson('/api/factories/factory', $this->headers())->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);
            $this->getJson('/api/secure-files/pmp/'.$file->id.'/details?order_id='.$order['id'], $this->headers())->assertOk()
                ->assertJsonPath('details.dimensions', '250 × 500')->assertJsonPath('details.inspection_required', true)->assertJsonCount(6, 'details.workshops');
            $other = $path[0] === $id ? $path[1] : $path[0];
            $this->getJson('/api/factories/factory/'.$other, $this->headers())->assertForbidden();
        }
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
        $drawing = $this->post('/api/engineers/uploadPmpFile', ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'file_category_id' => $category['id'], 'file' => UploadedFile::fake()->create('panel.dxf', 2), 'quantity' => 1, 'material_type' => 'Steel', 'thickness' => 2], $this->headers())->assertCreated()->assertJsonPath('file.file_category_id', $category['id'])->assertJsonPath('file.routing_factory_id', $workshop['id'])->json('file');
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

    public function test_every_existing_section_uses_its_saved_formats_for_uploads_and_policy_reads(): void
    {
        foreach (['SW', 'DXF', 'DLD', 'IQS', 'INFO', 'PDF'] as $code) {
            $category = $this->in(fn () => FileCategory::where('code', $code)->firstOrFail());
            $this->as($this->manager);
            $body = ['name' => $category->name, 'is_reference' => $category->is_reference, 'default_factory_id' => $category->default_factory_id, 'extensions' => [' .PDF ', 'nc']];
            $this->putJson('/api/file-categories/'.$category->id, $body, $this->headers())->assertOk();
            $this->as($this->engineer);
            $policy = collect($this->getJson('/api/factory-file-policies', $this->headers())->assertOk()->json('data'))->firstWhere('file_category_id', $category->id);
            $this->assertEqualsCanonicalizing(['pdf', 'nc'], array_column($policy['extensions'], 'extension'));
            foreach (['PDF', 'nc'] as $extension) {
                $file = $this->post('/api/engineers/uploadPmpFile', ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id,
                    'file_category_id' => $category->id, 'file' => UploadedFile::fake()->createWithContent($code.'.'.$extension, 'private drawing bytes')], $this->headers())
                    ->assertCreated()->assertJsonPath('file.file_category_id', $category->id)->json('file');
                $this->assertNull($file['quantity']);
                $this->get('/api/secure-files/pmp/'.$file['id'], $this->headers())->assertOk();
                $this->assertSame('private drawing bytes', Storage::disk('private')->get($file['path']));
            }
            $this->post('/api/engineers/uploadPmpFile', ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id,
                'factory_id' => $category->legacy_factory_id, 'file' => UploadedFile::fake()->create('not-allowed.txt', 1)], $this->headers())
                ->assertUnprocessable()->assertJsonValidationErrors('file');
            $this->as($this->manager); $body['extensions'] = [];
            $this->putJson('/api/file-categories/'.$category->id, $body, $this->headers())->assertOk();
            $this->as($this->engineer);
            $this->post('/api/engineers/uploadPmpFile', ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id,
                'file_category_id' => $category->id, 'file' => UploadedFile::fake()->create('now-blocked.pdf', 1)], $this->headers())->assertUnprocessable();
        }
    }

    public function test_dxf_metadata_is_required_for_actual_dxf_files_and_not_other_formats_in_the_section(): void
    {
        $category = $this->in(fn () => FileCategory::where('code', 'DXF')->firstOrFail());
        $this->as($this->manager);
        $this->putJson('/api/file-categories/'.$category->id, ['name' => $category->name, 'is_reference' => false, 'extensions' => ['dxf', 'pdf']], $this->headers())->assertOk();
        $this->as($this->engineer);
        $body = ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'file_category_id' => $category->id];
        $this->post('/api/engineers/uploadPmpFile', $body + ['file' => UploadedFile::fake()->create('no-metadata.dxf', 1)], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'material_type', 'thickness']);
        $this->post('/api/engineers/uploadPmpFile', $body + ['file' => UploadedFile::fake()->create('drawing.dxf', 1), 'quantity' => 2, 'material_type' => 'Steel', 'thickness' => 0], $this->headers())
            ->assertCreated()->assertJsonPath('file.quantity', 2)->assertJsonPath('file.material_type', 'Steel');
        $this->post('/api/engineers/uploadPmpFile', $body + ['file' => UploadedFile::fake()->create('reference.pdf', 1)], $this->headers())->assertCreated();
    }

    public function test_admins_and_managers_permanently_delete_empty_sections_without_deleting_workshops(): void
    {
        foreach ([$this->manager, $this->account('admin')] as $actor) {
            $this->as($actor);
            $category = $this->postJson('/api/file-categories', ['name' => 'Temporary folder', 'is_reference' => false, 'extensions' => ['pdf']], $this->headers())->assertCreated()->json('category');
            $this->deleteJson('/api/file-categories/'.$category['id'], [], $this->headers())->assertOk();
            $this->assertDatabaseMissing('file_categories', ['id' => $category['id']]);
            $this->assertDatabaseMissing('factory_file_extensions', ['factory_id' => $category['legacy_factory_id']]);
            $this->assertNotContains($category['id'], array_column($this->getJson('/api/file-categories?include_archived=1', $this->headers())->assertOk()->json('categories'), 'id'));
            $this->as($this->engineer);
            $this->post('/api/engineers/uploadPmpFile', ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id,
                'factory_id' => $category['legacy_factory_id'], 'file' => UploadedFile::fake()->create('stale.pdf', 1)], $this->headers())->assertUnprocessable();
            $this->assertDatabaseMissing('file_categories', ['legacy_factory_id' => $category['legacy_factory_id']]);
        }
        $category = $this->in(fn () => FileCategory::where('code', 'DXF')->firstOrFail());
        $this->as($this->manager);
        $this->deleteJson('/api/file-categories/'.$category->id, [], $this->headers())->assertOk();
        $this->getJson('/api/workshops', $this->headers())->assertOk()->assertJsonFragment(['id' => $this->factories['DXF']->id, 'is_workshop' => true]);
        $this->assertDatabaseHas('factories', ['id' => $this->factories['DXF']->id]);
    }

    public function test_section_deletion_preserves_existing_files_and_enforces_company_and_role_access(): void
    {
        $file = $this->file($this->factories['SW']->id, 'keep.sldprt');
        $category = $this->in(fn () => $file->fresh()->category);
        $this->as($this->engineer);
        $this->deleteJson('/api/file-categories/'.$category->id, [], $this->headers())->assertForbidden();
        $this->as($this->manager);
        $this->deleteJson('/api/file-categories/'.$category->id, [], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('category');
        // Legacy records with no category FK must block destructive removal too.
        DB::table('pmp_files')->where('id', $file->id)->update(['file_category_id' => null]);
        $this->deleteJson('/api/file-categories/'.$category->id, [], $this->headers())->assertUnprocessable();
        $foreign = app(CompanyContext::class)->run($this->other, fn () => FileCategory::firstOrFail());
        $this->deleteJson('/api/file-categories/'.$foreign->id, [], $this->headers())->assertNotFound();
        $this->get('/api/secure-files/pmp/'.$file->id, $this->headers())->assertOk();
        $this->assertSame('private bytes', Storage::disk('private')->get($file->path));
        $this->assertDatabaseHas('file_categories', ['id' => $category->id]);
    }

    public function test_task_revisions_accept_a_new_configured_format_and_reject_it_after_removal(): void
    {
        $this->as($this->manager);
        $category = $this->postJson('/api/file-categories', ['name' => 'Machine programs', 'is_reference' => false, 'default_factory_id' => $this->factories['POWDER']->id, 'extensions' => ['nc']], $this->headers())->assertCreated()->json('category');
        $this->as($this->engineer);
        $file = $this->post('/api/engineers/uploadPmpFile', ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'file_category_id' => $category['id'], 'file' => UploadedFile::fake()->createWithContent('program.nc', 'Original')], $this->headers())->assertCreated()->json('file');
        $order = $this->postJson('/api/engineers/engineer', $this->payload([$file['id']]), $this->headers())->assertCreated()->json('order');
        $url = '/api/tasks/'.$order['id'].'/files/'.$file['id'].'/versions';
        $version = $this->post($url, ['file' => UploadedFile::fake()->createWithContent('new.nc', 'Updated'), 'previous_revision_id' => null], $this->headers())->assertCreated()->json('files.0.task_file_revision_id');
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('wrong.pdf', 'Wrong format'), 'previous_revision_id' => $version], $this->headers())->assertUnprocessable();
        $this->as($this->manager);
        $this->putJson('/api/file-categories/'.$category['id'], ['name' => $category['name'], 'is_reference' => false, 'extensions' => []], $this->headers())->assertOk();
        $this->as($this->engineer);
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('blocked.nc', 'Blocked'), 'previous_revision_id' => $version], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('task_file_revisions', 2);
        $this->assertSame('Original', Storage::disk('private')->get($file['path']));
    }

    public function test_project_upload_size_limit_is_one_hundred_mebibytes(): void
    {
        $category = $this->in(fn () => FileCategory::where('code', 'PDF')->firstOrFail());
        $this->as($this->engineer);
        $body = ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'file_category_id' => $category->id];
        foreach ([11264, 102400] as $size) {
            $this->post('/api/engineers/uploadPmpFile', $body + ['file' => UploadedFile::fake()->create('large-'.$size.'.pdf', $size, 'application/pdf')], $this->headers())->assertCreated();
        }
        $this->post('/api/engineers/uploadPmpFile', $body + ['file' => UploadedFile::fake()->create('too-large.pdf', 102401, 'application/pdf')], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_uploaded_file_plan_becomes_the_task_route_and_preserves_attributes(): void
    {
        $weld = $this->factories['WELD'];
        $path = [$this->factories['DXF']->id, $this->factories['DLD']->id, $weld->id, $this->factories['POWDER']->id];
        $this->as($this->engineer);
        $file = $this->post('/api/engineers/uploadPmpFile', [
            'pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'factory_id' => $this->factories['DXF']->id,
            'file' => UploadedFile::fake()->createWithContent('route.dxf', 'DXF fixture'),
            'quantity' => 3, 'material_type' => 'Steel', 'thickness' => 1.5, 'dimensions' => '1000 × 2000 mm',
            'production_factory_ids' => json_encode($path), 'inspection_required' => 1,
        ], $this->headers())->assertCreated()->assertJsonPath('file.routing_factory_ids', $path)->assertJsonPath('file.inspection_required', true)->json('file');
        $body = array_replace($this->payload([$file['id']]), ['link_existing_files' => false, 'selected_files' => []]);
        $order = $this->postJson('/api/engineers/engineer', $body, $this->headers())->assertCreated()->json('order');
        $steps = $this->in(fn () => Order::findOrFail($order['id'])->factoryOrders()->orderBy('route_position')->get());
        $this->assertSame($path, $steps->pluck('factory_id')->all());
        foreach ($steps as $index => $step) {
            $this->assertSame($index ? [$steps[$index - 1]->id] : [], $step->dependencyIds());
            $attachment = $this->in(fn () => $step->files()->firstOrFail());
            $this->assertSame(3, $attachment->pivot->quantity);
            $this->assertSame('1000 × 2000 mm', $attachment->pivot->dimensions);
            $this->assertTrue((bool) $attachment->pivot->inspection_required);
        }
        $this->in(fn () => PmpFiles::findOrFail($file['id'])->update(['dimensions' => 'Changed library dimensions', 'inspection_required' => false]));
        $this->assertSame('1000 × 2000 mm', $this->in(fn () => $steps[0]->files()->firstOrFail()->pivot->dimensions));
        $this->assertCount(4, $steps, 'Inspection is a file requirement, not another workshop');
    }

    public function test_bulk_upload_retains_an_independent_plan_for_each_file(): void
    {
        $this->as($this->engineer);
        $plans = [
            ['production_factory_ids' => [$this->factories['IQS']->id, $this->factories['DLD']->id], 'inspection_required' => true],
            ['production_factory_ids' => [$this->factories['POWDER']->id], 'inspection_required' => false],
        ];
        $this->in(fn () => \App\Models\FactoryFileExtension::firstOrCreate(['factory_id' => $this->factories['IQS']->id, 'extension' => 'pdf']));
        $files = $this->post('/api/engineers/uploadPmpFile', [
            'pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'factory_id' => $this->factories['IQS']->id,
            'files' => [UploadedFile::fake()->createWithContent('model.igs', 'IGES'), UploadedFile::fake()->createWithContent('notes.pdf', 'PDF')],
            'file_plans' => json_encode($plans),
        ], $this->headers())->assertCreated()->assertJsonCount(2, 'files')->json('files');
        foreach ($files as $index => $file) {
            $this->assertSame($plans[$index]['production_factory_ids'], $file['routing_factory_ids']);
            $this->assertSame($plans[$index]['inspection_required'], $file['inspection_required']);
            Storage::disk('private')->assertExists($file['path']);
        }
        $this->assertSame('IGS', $this->in(fn () => FileCategory::where('code', 'IQS')->firstOrFail()->name));
    }

    public function test_bulk_upload_failures_are_atomic_and_attribute_files_must_be_individual(): void
    {
        $this->as($this->engineer);
        $base = ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'factory_id' => $this->factories['INFO']->id];
        $before = Storage::disk('private')->allFiles();
        foreach ([['one.txt', 'one.txt', 409], ['one.txt', 'part.dxf', 422], ['one.txt', 'unsafe.php', 422]] as [$first, $second, $status]) {
            $this->post('/api/engineers/uploadPmpFile', $base + ['files' => [UploadedFile::fake()->createWithContent($first, 'first'), UploadedFile::fake()->createWithContent($second, 'second')]], $this->headers())->assertStatus($status);
            $this->assertDatabaseCount('pmp_files', 0);
            $this->assertSame($before, Storage::disk('private')->allFiles());
        }
        $this->post('/api/engineers/uploadPmpFile', $base + ['files' => [UploadedFile::fake()->createWithContent('one.txt', 'first')],
            'expected_file_count' => 2], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->assertDatabaseCount('pmp_files', 0);
        $this->assertSame($before, Storage::disk('private')->allFiles());
        $this->in(fn () => \App\Models\FactoryFileExtension::firstOrCreate(['factory_id' => $this->factories['DXF']->id, 'extension' => 'ic']));
        $this->post('/api/engineers/uploadPmpFile', array_replace($base, ['factory_id' => $this->factories['DXF']->id,
            'file' => UploadedFile::fake()->createWithContent('part.ic', 'IC')]), $this->headers())->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'material_type', 'thickness']);
        $this->post('/api/engineers/uploadPmpFile', array_replace($base, ['factory_id' => $this->factories['DXF']->id,
            'file' => UploadedFile::fake()->createWithContent('part.ic', 'IC'), 'quantity' => 1, 'material_type' => 'Steel', 'thickness' => 0]), $this->headers())->assertCreated();
    }

    public function test_file_plans_reject_foreign_archived_reference_and_duplicate_targets(): void
    {
        $foreign = app(CompanyContext::class)->run($this->other, fn () => Factory::activeWorkshops()->firstOrFail());
        $archived = $this->in(fn () => Factory::create(['name' => 'Archived', 'value' => 'OLD', 'is_active' => false]));
        $this->as($this->engineer);
        foreach ([[$foreign->id], [$archived->id], [$this->factories['INFO']->id], [$this->factories['DXF']->id, $this->factories['DXF']->id]] as $ids) {
            $this->post('/api/engineers/uploadPmpFile', ['pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id,
                'factory_id' => $this->factories['IQS']->id, 'file' => UploadedFile::fake()->createWithContent('model.igs', 'IGES'),
                'production_factory_ids' => json_encode($ids)], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('production_factory_ids');
            $this->assertDatabaseCount('pmp_files', 0);
        }
    }

    public function test_upload_options_use_upload_permission_and_are_company_scoped(): void
    {
        $material = function (string $name) {
            $group = \App\Models\MaterialGroup::create(['name' => 'Sheet metal']);
            $category = \App\Models\MaterialCategory::create(['name' => 'Steel', 'material_group_id' => $group->id]);
            return \App\Models\Material::create(['description' => $name, 'material_category_id' => $category->id, 'width' => 1000, 'length' => 2000, 'thickness' => 2]);
        };
        $this->in(fn () => $material('Our steel'));
        app(CompanyContext::class)->run($this->other, fn () => $material('Private material'));
        $this->as($this->engineer);
        $this->getJson('/api/engineers/pmp-file-options', $this->headers())->assertOk()->assertJsonCount(1, 'materials')
            ->assertJsonPath('materials.0.description', 'Our steel')->assertJsonCount(6, 'workshops');
        $this->as($this->client);
        $this->getJson('/api/engineers/pmp-file-options', $this->headers())->assertForbidden();
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
        $dashboard->assertJsonPath('summary.factories', 6)->assertJsonPath('summary.factory_operators', 0)->assertJsonPath('summary.unassigned_factory_steps', 1)->assertJsonPath('summary.awaiting_engineer_confirmation', 0);
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

    public function test_file_details_show_the_task_snapshot_and_actual_destinations_to_every_authorized_role(): void
    {
        $file = $this->file($this->factories['DXF']->id, 'metadata.dxf');
        $this->in(fn () => $file->update(['quantity' => 2, 'material_type' => 'Steel', 'thickness' => 0,
            'dimensions' => '1000 × 2000', 'inspection_required' => true, 'production_factory_ids' => [$this->factories['IQS']->id]]));
        $this->as($this->engineer);
        $payload = $this->payload([$file->id]);
        $payload['selected_files'][0]['quantity'] = 3;
        $payload['file_routing'] = [['file_id' => $file->id, 'factory_ids' => [$this->factories['DXF']->id, $this->factories['DLD']->id]]];
        $order = $this->postJson('/api/engineers/engineer', $payload, $this->headers())->assertCreated()->json('order');
        $step = collect($order['factory_orders'])->firstWhere('factory_id', $this->factories['DXF']->id);
        $this->in(fn () => $file->update(['quantity' => 99, 'material_type' => 'Changed material', 'thickness' => 8,
            'dimensions' => 'Changed dimensions', 'inspection_required' => false, 'production_factory_ids' => [$this->factories['POWDER']->id]]));
        foreach ([$this->engineer, $this->manager, $this->account('admin'), $this->account('operator', $this->factories['DXF']->id)] as $actor) {
            $this->as($actor);
            $response = $this->getJson('/api/secure-files/pmp/'.$file->id.'/details?factory_order_id='.$step['id'], $this->headers())
                ->assertOk()->assertJsonPath('details.source', 'task')->assertJsonPath('details.quantity', 3)
                ->assertJsonPath('details.material_type', 'Steel')->assertJsonPath('details.dimensions', '1000 × 2000')
                ->assertJsonPath('details.inspection_required', true)->assertJsonPath('details.workshops.0.current', true)
                ->assertJsonMissingPath('details.path')->assertJsonMissingPath('details.files');
            $this->assertEquals(0, $response->json('details.thickness'));
            $this->assertSame([$this->factories['DXF']->id, $this->factories['DLD']->id], array_column($response->json('details.workshops'), 'id'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->as($this->engineer);
        $this->getJson('/api/secure-files/pmp/'.$file->id.'/details', $this->headers())->assertOk()
            ->assertJsonPath('details.source', 'project')->assertJsonPath('details.quantity', 99)
            ->assertJsonPath('details.dimensions', 'Changed dimensions')->assertJsonPath('details.inspection_required', false)
            ->assertJsonPath('details.workshops.0.id', $this->factories['POWDER']->id);
    }

    public function test_file_details_preserve_empty_task_attributes_instead_of_inheriting_later_library_values(): void
    {
        $file = $this->file($this->factories['DXF']->id, 'legacy-empty.dxf');
        $this->as($this->engineer);
        $order = $this->postJson('/api/engineers/engineer', $this->payload([$file->id]), $this->headers())->assertCreated()->json('order');
        $this->in(fn () => $file->update(['dimensions' => 'Later dimensions', 'material_type' => 'Later material', 'inspection_required' => true]));
        $this->getJson('/api/secure-files/pmp/'.$file->id.'/details?order_id='.$order['id'], $this->headers())->assertOk()
            ->assertJsonPath('details.dimensions', null)->assertJsonPath('details.material_type', null)->assertJsonPath('details.inspection_required', false);
    }

    public function test_file_details_enforce_company_workshop_operator_and_task_visibility(): void
    {
        $file = $this->file($this->factories['DXF']->id, 'allowed.dxf');
        $private = $this->file($this->factories['INFO']->id, 'private-for-bending.txt');
        $this->as($this->engineer);
        $payload = $this->payload([$file->id, $private->id]);
        $payload['reference_file_visibility'] = [['file_id' => $private->id, 'factory_ids' => [$this->factories['DLD']->id]]];
        $order = $this->postJson('/api/engineers/engineer', $payload, $this->headers())->assertCreated()->json('order');
        $otherOperator = $this->account('operator', $this->factories['DXF']->id);
        $second = $this->postJson('/api/engineers/engineer', $this->payload([$file->id]) + [
            'factory_operators' => [['factory_id' => $this->factories['DXF']->id, 'user_id' => $otherOperator->id]],
        ], $this->headers())->assertCreated()->json('order');
        $operator = $this->account('operator', $this->factories['DXF']->id);
        $this->as($operator);
        $this->getJson('/api/secure-files/pmp/'.$file->id.'/details?order_id='.$order['id'], $this->headers())->assertOk();
        $this->getJson('/api/secure-files/pmp/'.$private->id.'/details?order_id='.$order['id'], $this->headers())->assertForbidden();
        $this->getJson('/api/secure-files/pmp/'.$file->id.'/details?order_id='.$second['id'], $this->headers())->assertForbidden();
        $this->getJson('/api/secure-files/pmp/'.$file->id.'/details?factory_order_id='.$second['factory_orders'][0]['id'], $this->headers())->assertForbidden();
        $this->as($this->account('engineer'));
        $this->getJson('/api/secure-files/pmp/'.$file->id.'/details?order_id='.$order['id'], $this->headers())->assertForbidden();
        $this->as($this->client);
        $this->getJson('/api/secure-files/pmp/'.$file->id.'/details', $this->headers())->assertForbidden();
        $foreign = app(CompanyContext::class)->run($this->other, function () {
            $project = Pmp::create(['group' => '998', 'group_name' => 'Private project', 'admin_confirmation' => false]);
            $remote = RemoteNumber::create(['pmp_id' => $project->id, 'remote_number' => '01', 'remote_number_name' => 'Private subgroup']);
            return PmpFiles::create(['pmp_id' => $project->id, 'remote_number_id' => $remote->id,
                'factory_id' => Factory::where('value', 'DXF')->value('id'), 'original_name' => 'foreign.dxf', 'path' => 'foreign.dxf']);
        });
        $this->as($this->manager);
        $this->getJson('/api/secure-files/pmp/'.$foreign->id.'/details', $this->headers())->assertNotFound();
    }

    public function test_revision_details_use_the_requested_task_file_and_reject_mismatched_context(): void
    {
        $file = $this->file($this->factories['DXF']->id, 'original.dxf');
        $other = $this->file($this->factories['DXF']->id, 'other.dxf');
        $this->as($this->engineer);
        $order = $this->postJson('/api/engineers/engineer', $this->payload([$file->id]), $this->headers())->assertCreated()->json('order');
        $revision = $this->in(fn () => \App\Models\TaskFileRevision::create(['order_id' => $order['id'], 'pmp_file_id' => $file->id,
            'user_id' => $this->engineer->id, 'version' => 2, 'file_path' => 'private/revision.dxf', 'original_name' => 'revision.dxf', 'file_size' => 8]));
        $this->getJson('/api/secure-files/pmp/'.$file->id.'/details?revision_id='.$revision->id, $this->headers())->assertOk()
            ->assertJsonPath('details.original_name', 'revision.dxf')->assertJsonPath('details.order_id', $order['id']);
        $this->getJson('/api/secure-files/pmp/'.$other->id.'/details?revision_id='.$revision->id, $this->headers())->assertNotFound();
        $this->getJson('/api/secure-files/pmp/'.$file->id.'/details?factory_order_id[]=1', $this->headers())->assertUnprocessable();
    }

    private function payload(array $ids): array { return ['user_id' => $this->client->id, 'pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'name' => '987.01', 'description' => 'Catalog task', 'finish_date' => '2099-01-01', 'link_existing_files' => true, 'selected_files' => array_map(fn ($id) => ['id' => $id, 'quantity' => 1], $ids)]; }
    public function test_managers_keep_workshop_functions_engineers_tick_them_per_file_and_tasks_keep_them(): void
    {
        $laser = $this->factories['DXF']; $bend = $this->factories['DLD'];
        $this->as($this->manager);
        $engrave = $this->postJson('/api/workshops/'.$laser->id.'/functions', ['name' => ' Engraving '], $this->headers())->assertCreated()->assertJsonPath('function.name', 'Engraving')->json('function');
        $this->postJson('/api/workshops/'.$laser->id.'/functions', ['name' => 'Deburring'], $this->headers())->assertCreated();
        $this->postJson('/api/workshops/'.$laser->id.'/functions', ['name' => 'Engraving'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('name');
        $flange = $this->postJson('/api/workshops/'.$bend->id.'/functions', ['name' => 'Flange'], $this->headers())->assertCreated()->json('function');
        $old = $this->postJson('/api/workshops/'.$bend->id.'/functions', ['name' => 'Old'], $this->headers())->assertCreated()->json('function');
        $this->putJson('/api/workshops/'.$bend->id.'/functions/'.$old['id'], ['is_active' => false], $this->headers())->assertOk()->assertJsonPath('function.is_active', false);
        $this->putJson('/api/workshops/'.$laser->id.'/functions/'.$flange['id'], ['name' => 'Moved'], $this->headers())->assertNotFound();
        $listed = collect($this->getJson('/api/workshops', $this->headers())->assertOk()->json('workshops'))->keyBy('id');
        $this->assertSame(['Engraving', 'Deburring'], array_column($listed[$laser->id]['functions'], 'name'));
        $this->assertCount(2, $listed[$bend->id]['functions'], 'managers still see archived functions');

        $this->as($this->engineer);
        $this->postJson('/api/workshops/'.$laser->id.'/functions', ['name' => 'Not allowed'], $this->headers())->assertForbidden();
        $options = collect($this->getJson('/api/engineers/pmp-file-options', $this->headers())->assertOk()->json('workshops'))->keyBy('id');
        $this->assertSame(['Flange'], array_column($options[$bend->id]['functions'], 'name'), 'archived functions are not offered');
        $upload = fn (array $functions, array $path) => $this->post('/api/engineers/uploadPmpFile', [
            'pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'factory_id' => $laser->id,
            'file' => UploadedFile::fake()->createWithContent('part-'.uniqid().'.dxf', 'DXF fixture'),
            'quantity' => 2, 'material_type' => 'Steel', 'thickness' => 2,
            'production_factory_ids' => json_encode($path), 'production_function_ids' => json_encode($functions),
        ], $this->headers());
        $upload([$flange['id']], [$laser->id])->assertUnprocessable()->assertJsonValidationErrors('production_function_ids');
        $upload([$old['id']], [$laser->id, $bend->id])->assertUnprocessable()->assertJsonValidationErrors('production_function_ids');
        $file = $upload([$engrave['id'], $flange['id']], [$laser->id, $bend->id])->assertCreated()
            ->assertJsonPath('file.production_function_ids', [$engrave['id'], $flange['id']])->json('file');

        $body = array_replace($this->payload([$file['id']]), ['link_existing_files' => false, 'selected_files' => []]);
        $order = $this->postJson('/api/engineers/engineer', $body, $this->headers())->assertCreated()->json('order');
        $steps = $this->in(fn () => Order::findOrFail($order['id'])->factoryOrders()->with('files')->get()->keyBy('factory_id'));
        $this->assertSame([['id' => $engrave['id'], 'name' => 'Engraving']], $steps[$laser->id]->files->first()->pivot->production_functions);
        $this->assertSame([['id' => $flange['id'], 'name' => 'Flange']], $steps[$bend->id]->files->first()->pivot->production_functions);

        // A later rename changes the library, not the task the operator was given.
        $this->as($this->manager);
        $this->putJson('/api/workshops/'.$laser->id.'/functions/'.$engrave['id'], ['name' => 'Laser marking'], $this->headers())->assertOk();
        $this->as($this->account('operator', $laser->id));
        $task = $this->getJson('/api/secure-files/pmp/'.$file['id'].'/details?factory_order_id='.$steps[$laser->id]->id, $this->headers())->assertOk();
        $this->assertSame(['Engraving'], array_column($task->json('details.workshops.0.functions'), 'name'));
        $this->assertTrue($task->json('details.workshops.0.current'));
        $this->as($this->engineer);
        $project = collect($this->getJson('/api/secure-files/pmp/'.$file['id'].'/details', $this->headers())->assertOk()->json('details.workshops'))->keyBy('id');
        $this->assertSame(['Laser marking'], array_column($project[$laser->id]['functions'], 'name'));
        $this->assertSame(['Flange'], array_column($project[$bend->id]['functions'], 'name'));
    }

    public function test_workshop_functions_never_cross_company_boundaries(): void
    {
        [$foreignWorkshop, $foreign] = app(CompanyContext::class)->run($this->other, function () {
            $workshop = Factory::where('value', 'DXF')->firstOrFail();
            return [$workshop, \App\Models\WorkshopFunction::create(['factory_id' => $workshop->id, 'name' => 'Secret'])];
        });
        $this->as($this->manager);
        $this->postJson('/api/workshops/'.$foreignWorkshop->id.'/functions', ['name' => 'Intrusion'], $this->headers())->assertNotFound();
        $this->putJson('/api/workshops/'.$this->factories['DXF']->id.'/functions/'.$foreign->id, ['name' => 'Taken'], $this->headers())->assertNotFound();
        $this->assertSame([], collect($this->getJson('/api/workshops', $this->headers())->json('workshops'))->flatMap(fn ($row) => $row['functions'] ?? [])->all());
        $this->as($this->engineer);
        $this->post('/api/engineers/uploadPmpFile', [
            'pmp_id' => $this->pmp->id, 'remote_number_id' => $this->remote->id, 'factory_id' => $this->factories['DXF']->id,
            'file' => UploadedFile::fake()->createWithContent('foreign.dxf', 'DXF fixture'),
            'quantity' => 1, 'material_type' => 'Steel', 'thickness' => 1,
            'production_factory_ids' => json_encode([$this->factories['DXF']->id]), 'production_function_ids' => json_encode([$foreign->id]),
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('production_function_ids');
        $this->assertSame('Secret', app(CompanyContext::class)->run($this->other, fn () => $foreign->fresh()->name));
    }

    private function in(callable $callback): mixed { return app(CompanyContext::class)->run($this->company, $callback); }
    private function account(string $role, ?int $factory = null): User { return $this->in(function () use ($role, $factory) { $user = User::create(['name' => $role, 'email' => $role.'-'.uniqid().'@example.invalid', 'password' => 'test-password', 'role_id' => Role::where('name', $role)->value('id'), 'factory_id' => $factory]); $m = CompanyMembership::where('user_id', $user->id)->first(); $m->permissions()->sync(Permission::whereIn('slug', ['orders.view', 'orders.create', 'orders.update', 'factory.view', 'factory.download', 'factory.order_update', 'pmp.view', 'pmp_files.view', 'pmp_files.upload'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all()); return $user; }); }
    private function as(User $user): void { app(CompanyContext::class)->set(null); $user->unsetRelation('role')->unsetRelation('factory'); Sanctum::actingAs($user); }
    private function headers(): array { return ['Accept' => 'application/json', 'X-Company-ID' => (string) $this->company->id]; }
}
