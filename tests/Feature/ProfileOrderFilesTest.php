<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Profile\ProfileOrderFileController;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileOrderFilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['orders', 'files', 'selected_files', 'pmp_files', 'task_file_revisions', 'file_categories'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->id(); $t->unsignedBigInteger('company_id')->default(1);
                if ($name === 'orders') { $t->unsignedBigInteger('user_id'); return; }
                if ($name === 'file_categories') return;
                $t->unsignedBigInteger('order_id')->nullable();
                $t->string('path')->nullable(); $t->string('original_name')->nullable();
                if ($name === 'selected_files') { $t->unsignedBigInteger('pmp_file_id'); $t->unsignedBigInteger('task_file_revision_id')->nullable(); $t->integer('quantity')->default(1); }
                if ($name === 'pmp_files') $t->unsignedBigInteger('file_category_id')->nullable();
                if ($name === 'task_file_revisions') { $t->unsignedBigInteger('pmp_file_id'); $t->string('file_path'); }
            });
        }
        $company = new Company(); $company->id = 1;
        app(CompanyContext::class)->set($company);
        DB::table('orders')->insert([['id' => 1, 'user_id' => 7, 'company_id' => 1], ['id' => 2, 'user_id' => 8, 'company_id' => 1], ['id' => 3, 'user_id' => 7, 'company_id' => 2]]);
        DB::table('files')->insert(['id' => 1, 'order_id' => 1, 'path' => 'orders/a.txt', 'original_name' => 'a.txt']);
        Storage::fake('private'); Storage::disk('private')->put('orders/a.txt', 'test');
    }

    private function request(array $params = []): Request
    {
        $user = new User(); $user->id = 7;
        $request = Request::create('/api/profile/orders/1/files', 'GET', $params);
        $request->setUserResolver(fn () => $user);
        return $request;
    }

    public function test_only_own_company_orders_can_be_listed(): void
    {
        $controller = new ProfileOrderFileController();
        $files = $controller->index($this->request(), 1)->getData(true)['files'];
        $this->assertSame('a.txt', $files[0]['original_name']);
        $this->assertArrayNotHasKey('path', $files[0]);
        foreach ([2, 3] as $id) {
            try { $controller->index($this->request(), $id); $this->fail('Foreign order exposed'); }
            catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) { $this->assertTrue(true); }
        }
    }

    public function test_own_file_download_and_missing_file(): void
    {
        $response = (new ProfileOrderFileController())->show($this->request(['download' => 1]), 1, 'order-1');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new ProfileOrderFileController())->show($this->request(), 1, 'order-999');
    }

    public function test_selected_file_uses_pinned_revision_and_cannot_fall_back(): void
    {
        DB::table('pmp_files')->insert(['id' => 10, 'path' => 'current.txt', 'original_name' => 'current.txt']);
        DB::table('task_file_revisions')->insert(['id' => 20, 'order_id' => 1, 'pmp_file_id' => 10, 'file_path' => 'pinned.txt', 'original_name' => 'pinned.txt']);
        DB::table('selected_files')->insert(['id' => 2, 'order_id' => 1, 'pmp_file_id' => 10, 'task_file_revision_id' => 20]);
        Storage::disk('private')->put('pinned.txt', 'pinned');
        $controller = new ProfileOrderFileController();
        $response = $controller->show($this->request(), 1, 'selected-2');
        $this->assertSame('pinned.txt', $response->getFile()->getFilename());
        DB::table('task_file_revisions')->where('id', 20)->update(['order_id' => 2]);
        $this->assertCount(1, $controller->index($this->request(), 1)->getData(true)['files']);
    }

    public function test_path_traversal_is_rejected(): void
    {
        DB::table('files')->where('id', 1)->update(['path' => '../secret.txt']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new ProfileOrderFileController())->show($this->request(), 1, 'order-1');
    }
    public function test_guest_cannot_request_customer_files(): void
    {
        $this->getJson('/api/profile/orders/1/files')->assertUnauthorized();
    }

    public function test_active_content_is_never_served_as_html(): void
    {
        DB::table('files')->where('id', 1)->update(['original_name' => 'page.html']);
        $response = (new ProfileOrderFileController())->show($this->request(), 1, 'order-1');
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

}
