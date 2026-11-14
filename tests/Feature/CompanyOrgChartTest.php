<?php

namespace Tests\Feature;

use App\Models\{Company, CompanyMembership, CompanyPosition, Factory, Role, User};
use App\Support\{CompanyContext, MembershipAssignments};
use Database\Seeders\{FactorySeeder, RoleTableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Schema};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyOrgChartTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private User $manager;
    private User $admin;
    private array $factories;
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
        $this->a = Company::where('slug', 'metalworks')->firstOrFail();
        $this->b = Company::create(['name' => 'Second Works', 'slug' => 'second']);
        foreach ([$this->a, $this->b] as $company) $this->in($company, fn () => (new FactorySeeder)->run());
        $this->factories = $this->in($this->a, fn () => Factory::activeWorkshops()->orderBy('id')->limit(2)->get()->all());
        $this->manager = $this->account($this->a, 'manager@example.invalid', 'manager');
        $this->admin = $this->account($this->a, 'admin@example.invalid', 'admin');
    }

    public function test_a_manager_builds_positions_under_positions_and_moves_employees_between_them(): void
    {
        $employee = $this->account($this->a, 'employee@example.invalid', 'engineer');
        $this->as($this->manager);
        $head = $this->postJson('/api/org-chart/positions', ['name' => 'Production head', 'role_id' => $this->role('manager')], $this->headers())
            ->assertCreated()->json('positions.0');
        $laser = $this->position('Laser operator', 'laser', $this->factories[0]->id, $head['id']);
        $design = $this->position('Designer', 'engineer', null, $head['id']);
        $chart = $this->getJson('/api/org-chart', $this->headers())->assertOk();
        $this->assertSame([$head['id'], $head['id']], [$this->find($chart, $laser)['parent_id'], $this->find($chart, $design)['parent_id']]);
        $unplaced = collect($chart->json('unplaced'))->firstWhere('user_id', $employee->id);
        $this->assertNotNull($unplaced, 'An employee outside the chart is listed to be placed.');

        // Moving into the laser position replaces the engineer access with the position's.
        $this->postJson("/api/org-chart/positions/$laser/members", ['user_id' => $employee->id, 'replace_assignment_id' => $unplaced['assignment_id']], $this->headers())->assertOk();
        $rows = $this->rows($employee);
        $this->assertCount(1, $rows);
        $this->assertSame(['laser', $this->factories[0]->id, $laser], [$rows[0]['role']['name'], $rows[0]['factory_id'], $rows[0]['position_id']]);
        $this->assertSame($this->role('laser'), (int) $this->membership($employee)->role_id);

        // A second position adds to the first; leaving one keeps the other, the last cannot be left.
        $this->postJson("/api/org-chart/positions/$design/members", ['user_id' => $employee->id], $this->headers())->assertOk();
        $this->assertEqualsCanonicalizing(['laser', 'engineer'], array_column(array_column($this->rows($employee), 'role'), 'name'));
        $this->deleteJson("/api/org-chart/positions/$laser/members/{$employee->id}", [], $this->headers())->assertOk();
        $this->assertSame(['engineer'], array_column(array_column($this->rows($employee), 'role'), 'name'));
        $this->deleteJson("/api/org-chart/positions/$design/members/{$employee->id}", [], $this->headers())->assertUnprocessable();

        // Moving with a replaced assignment leaves the old position empty.
        $current = $this->rows($employee)[0]['id'];
        $this->postJson("/api/org-chart/positions/$laser/members", ['user_id' => $employee->id, 'replace_assignment_id' => $current], $this->headers())->assertOk();
        $chart = $this->getJson('/api/org-chart', $this->headers())->assertOk();
        $this->assertSame([$employee->id], array_column($this->find($chart, $laser)['members'], 'user_id'));
        $this->assertSame([], $this->find($chart, $design)['members']);

        // The position editor keeps the position while the access stays the same.
        $this->putJson("/api/staff-assignments/{$employee->id}", ['assignments' => [['role_id' => $this->role('laser'), 'factory_id' => $this->factories[0]->id]]], $this->headers())->assertOk();
        $this->assertSame($laser, $this->rows($employee)[0]['position_id']);
    }

    public function test_changing_a_position_changes_its_holders_and_the_tree_stays_a_tree(): void
    {
        $employee = $this->account($this->a, 'employee@example.invalid', 'engineer');
        $this->as($this->manager);
        $head = $this->position('Production head', 'manager');
        $laser = $this->position('Cutter', 'laser', $this->factories[0]->id, $head);
        $sub = $this->position('Cutter assistant', 'laser', $this->factories[1]->id, $laser);
        $this->postJson("/api/org-chart/positions/$laser/members", ['user_id' => $employee->id, 'replace_assignment_id' => $this->rows($employee)[0]['id']], $this->headers())->assertOk();

        // A new workshop for the position moves its holder to that workshop.
        $this->putJson("/api/org-chart/positions/$laser", ['name' => 'Cutter', 'role_id' => $this->role('bend'), 'factory_id' => $this->factories[1]->id, 'parent_id' => $head], $this->headers())->assertOk();
        $row = $this->rows($employee)[0];
        $this->assertSame(['bend', $this->factories[1]->id, $laser], [$row['role']['name'], $row['factory_id'], $row['position_id']]);

        // No position may sit under itself or its own subordinates; production needs a workshop.
        $this->putJson("/api/org-chart/positions/$laser", ['name' => 'Cutter', 'role_id' => $this->role('bend'), 'factory_id' => $this->factories[1]->id, 'parent_id' => $sub], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->putJson("/api/org-chart/positions/$laser", ['name' => 'Cutter', 'role_id' => $this->role('bend'), 'parent_id' => $head], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('factory_id');

        // An occupied position stays; an empty one goes and its subordinates move up.
        $this->deleteJson("/api/org-chart/positions/$laser", [], $this->headers())->assertUnprocessable();
        $this->deleteJson("/api/org-chart/positions/$head", [], $this->headers())->assertOk();
        $chart = $this->getJson('/api/org-chart', $this->headers())->assertOk();
        $this->assertNull($this->find($chart, $laser)['parent_id']);
        $this->assertSame($laser, $this->find($chart, $sub)['parent_id']);
    }

    public function test_a_position_can_leave_its_holders_access_as_it_is(): void
    {
        $employee = $this->account($this->a, 'assistant@example.invalid', 'engineer');
        $this->as($this->manager);
        $engineer = $this->position('Engineer', 'engineer');
        // An assistant under the engineer gets no access of the position's own.
        $chart = $this->postJson('/api/org-chart/positions', ['name' => 'Assistant', 'role_id' => null, 'factory_id' => $this->factories[0]->id, 'parent_id' => $engineer], $this->headers())->assertCreated();
        $assistant = collect($chart->json('positions'))->firstWhere('name', 'Assistant');
        $this->assertSame([$engineer, null, null], [$assistant['parent_id'], $assistant['role'], $assistant['factory']]);

        // Placing someone there keeps the access they have.
        $this->postJson("/api/org-chart/positions/{$assistant['id']}/members", ['user_id' => $employee->id], $this->headers())->assertOk();
        $row = $this->rows($employee)[0];
        $this->assertSame(['engineer', null, $assistant['id']], [$row['role']['name'], $row['factory_id'], $row['position_id']]);

        // Giving the position an access level gives it to its holders; taking it away keeps theirs.
        $this->putJson("/api/org-chart/positions/{$assistant['id']}", ['name' => 'Assistant', 'role_id' => $this->role('operator'), 'factory_id' => $this->factories[0]->id, 'parent_id' => $engineer], $this->headers())->assertOk();
        $this->assertSame(['operator', $this->factories[0]->id], [$this->rows($employee)[0]['role']['name'], $this->rows($employee)[0]['factory_id']]);
        $this->putJson("/api/org-chart/positions/{$assistant['id']}", ['name' => 'Assistant', 'role_id' => null, 'parent_id' => $engineer], $this->headers())->assertOk();
        $row = $this->rows($employee)[0];
        $this->assertSame(['operator', $this->factories[0]->id, $assistant['id']], [$row['role']['name'], $row['factory_id'], $row['position_id']]);

        // With several places the manager says which one moves in.
        $this->in($this->a, fn () => MembershipAssignments::sync($this->membership($employee), [
            ['role_id' => $this->role('operator'), 'factory_id' => $this->factories[0]->id],
            ['role_id' => $this->role('engineer'), 'factory_id' => null],
        ]));
        $helper = collect($this->postJson('/api/org-chart/positions', ['name' => 'Helper', 'parent_id' => $engineer], $this->headers())->assertCreated()->json('positions'))->firstWhere('name', 'Helper');
        $this->postJson("/api/org-chart/positions/{$helper['id']}/members", ['user_id' => $employee->id], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('replace_assignment_id');
        $engineerRow = collect($this->rows($employee))->firstWhere('role.name', 'engineer');
        $this->postJson("/api/org-chart/positions/{$helper['id']}/members", ['user_id' => $employee->id, 'replace_assignment_id' => $engineerRow['id']], $this->headers())->assertOk();
        $this->assertSame($helper['id'], collect($this->rows($employee))->firstWhere('role.name', 'engineer')['position_id']);
    }

    public function test_administrators_stay_with_the_platform_administrator_and_companies_stay_apart(): void
    {
        $this->as($this->manager);
        $this->postJson('/api/org-chart/positions', ['name' => 'Owner', 'role_id' => $this->role('admin')], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $head = $this->position('Head', 'manager');
        $this->postJson("/api/org-chart/positions/$head/members", ['user_id' => $this->admin->id], $this->headers())->assertForbidden();
        $admins = collect($this->getJson('/api/org-chart', $this->headers())->json('unplaced'))->where('user_id', $this->admin->id);
        $this->assertTrue($admins->every(fn ($row) => $row['locked'] === true));

        $other = $this->account($this->b, 'other@example.invalid', 'manager');
        $this->as($other);
        $this->getJson('/api/org-chart', ['X-Company-ID' => $this->b->id])->assertOk()->assertJsonCount(0, 'positions');
        $this->putJson("/api/org-chart/positions/$head", ['name' => 'Taken', 'role_id' => $this->role('manager')], ['X-Company-ID' => $this->b->id])->assertNotFound();
        $engineer = $this->account($this->a, 'engineer@example.invalid', 'engineer');
        $this->as($engineer);
        $this->getJson('/api/org-chart', $this->headers())->assertForbidden();
    }

    public function test_an_empty_chart_starts_from_the_positions_employees_already_hold(): void
    {
        $employee = $this->account($this->a, 'employee@example.invalid', 'engineer');
        $this->in($this->a, fn () => MembershipAssignments::sync($this->membership($employee), [
            ['role_id' => $this->role('engineer'), 'factory_id' => null],
            ['role_id' => $this->role('laser'), 'factory_id' => $this->factories[0]->id],
        ]));
        $this->as($this->manager);
        $chart = $this->postJson('/api/org-chart/generate', [], $this->headers())->assertCreated();
        $positions = collect($chart->json('positions'));
        $byRole = fn (string $name) => $positions->first(fn ($row) => $row['role']['name'] === $name);
        $this->assertNull($byRole('admin')['parent_id']);
        $this->assertSame($byRole('admin')['id'], $byRole('manager')['parent_id']);
        $this->assertSame($byRole('manager')['id'], $byRole('engineer')['parent_id']);
        $this->assertSame($byRole('manager')['id'], $byRole('laser')['parent_id']);
        $this->assertSame($this->factories[0]->id, $byRole('laser')['factory']['id']);
        $this->assertSame([], $chart->json('unplaced'));
        $this->assertSame([$employee->id], array_column($byRole('laser')['members'], 'user_id'));
        $this->assertSame($this->role('engineer'), (int) $this->membership($employee)->role_id, 'Nobody’s access changes.');
        $this->postJson('/api/org-chart/generate', [], $this->headers())->assertUnprocessable();
    }

    private function position(string $name, string $role, ?int $factoryId = null, ?int $parentId = null): int
    {
        $chart = $this->postJson('/api/org-chart/positions', ['name' => $name, 'role_id' => $this->role($role), 'factory_id' => $factoryId, 'parent_id' => $parentId], $this->headers())->assertCreated();
        return (int) collect($chart->json('positions'))->where('name', $name)->max('id');
    }
    private function find($chart, int $id): array { return collect($chart->json('positions'))->firstWhere('id', $id); }
    private function rows(User $user): array { return $this->in($this->a, fn () => MembershipAssignments::rows($this->membership($user)->fresh())); }
    private function in(Company $company, callable $fn): mixed { return app(CompanyContext::class)->run($company, $fn); }
    private function role(string $name): int { return (int) Role::where('name', $name)->value('id'); }
    private function account(Company $company, string $email, string $role): User { return $this->in($company, fn () => User::create(['name' => 'Test Person', 'email' => $email, 'password' => 'test-password', 'role_id' => $this->role($role)])); }
    private function membership(User $user, ?Company $company = null): CompanyMembership { return CompanyMembership::where('user_id', $user->id)->where('company_id', ($company ?? $this->a)->id)->firstOrFail(); }
    private function headers(): array { return ['X-Company-ID' => $this->a->id]; }
    private function as(User $user): void { app(CompanyContext::class)->set(null); $user->unsetRelation('role')->unsetRelation('factory'); Sanctum::actingAs($user); }
}
