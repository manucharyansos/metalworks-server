<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CadModelFilePolicyTest extends TestCase
{
    private const DEFAULTS = ['iges', 'igs', 'iqs', 'obj', 'step', 'stl', 'stp'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('factories', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        Schema::create('factory_file_extensions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('factory_id');
            $table->string('extension');
            $table->timestamps();
            $table->unique(['factory_id', 'extension']);
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_legacy_iqs_default_accepts_all_previewable_model_formats(): void
    {
        $factoryId = $this->factory('IQS', ['iqs']);
        $this->migration()->up();
        $this->assertSame(self::DEFAULTS, $this->extensions($factoryId));
    }

    public function test_custom_and_empty_iqs_policies_are_preserved(): void
    {
        foreach ([[], ['pdf'], ['iqs', 'stp'], ['*']] as $extensions) {
            $factoryId = $this->factory('IQS', $extensions);
            $this->migration()->up();
            sort($extensions);
            $this->assertSame($extensions, $this->extensions($factoryId));
        }
    }

    public function test_other_factories_keep_their_existing_policies(): void
    {
        $sw = $this->factory('SW', ['sldprt']);
        $dxf = $this->factory('DXF', ['dxf']);
        $this->migration()->up();
        $this->assertSame(['sldprt'], $this->extensions($sw));
        $this->assertSame(['dxf'], $this->extensions($dxf));
    }

    public function test_migration_can_run_twice_and_rollback_to_legacy_default(): void
    {
        $factoryId = $this->factory('IQS', ['iqs']);
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $this->assertSame(self::DEFAULTS, $this->extensions($factoryId));
        $migration->down();
        $this->assertSame(['iqs'], $this->extensions($factoryId));
    }

    public function test_rollback_preserves_later_admin_policy_changes(): void
    {
        $factoryId = $this->factory('IQS', ['iqs']);
        $migration = $this->migration();
        $migration->up();
        DB::table('factory_file_extensions')->where('factory_id', $factoryId)
            ->where('extension', 'obj')->delete();
        $changed = $this->extensions($factoryId);
        $migration->down();
        $this->assertSame($changed, $this->extensions($factoryId));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_01_120000_enable_iqs_cad_model_extensions.php');
    }

    private function factory(string $value, array $extensions): int
    {
        $id = DB::table('factories')->insertGetId(['value' => $value]);
        foreach ($extensions as $extension) {
            DB::table('factory_file_extensions')->insert([
                'factory_id' => $id,
                'extension' => $extension,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return $id;
    }

    private function extensions(int $factoryId): array
    {
        return DB::table('factory_file_extensions')->where('factory_id', $factoryId)
            ->orderBy('extension')->pluck('extension')->all();
    }
}
