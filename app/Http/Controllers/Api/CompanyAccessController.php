<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Company, CompanyMembership, Factory, Role, User};
use App\Support\CompanyContext;
use App\Support\CompanyManagement;
use App\Support\MembershipAssignments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyAccessController extends Controller
{
    private const ROLES = ['authenticatedUser', 'manager', 'engineer', 'laser', 'bend', 'powder_catting'];

    public function show(Request $request, string $user)
    {
        return $this->withTarget($request, $user, fn (User $target) => $this->showUser($request, $target));
    }

    private function showUser(Request $request, User $user)
    {
        $this->assertTarget($user);
        $client = $this->isClient($user);
        $companies = CompanyManagement::companies($request->user());
        $memberships = CompanyMembership::where('user_id', $user->id)->whereIn('company_id', $companies->modelKeys())->get()->keyBy('company_id');
        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'type' => $client ? 'client' : 'employee'],
            'current_company_id' => app(CompanyContext::class)->id(),
            'unmanaged_companies' => CompanyManagement::unassignedDirectory($companies),
            'roles' => $client ? [] : Role::whereIn('name', $this->roles($request->user()))->orderBy('name')->get(['id', 'name', 'value']),
            'companies' => $companies->map(function (Company $company) use ($memberships, $request, $client) {
                $membership = $memberships->get($company->id);
                $roleNames = app(CompanyContext::class)->run($company, fn () => MembershipAssignments::roleNames($membership));
                $readOnly = (!$request->user()->is_platform_admin && in_array('admin', $roleNames, true))
                    || ($client && (bool) array_intersect($roleNames, MembershipAssignments::STAFF_ROLES));
                return [
                    'id' => $company->id, 'name' => $company->name, 'read_only' => $readOnly,
                    'selection_locked' => $readOnly || $company->id === app(CompanyContext::class)->id(),
                    'factories' => $client ? [] : Factory::withoutGlobalScope('company')->where('company_id', $company->id)->orderBy('name')->get(['id', 'name']),
                    'access' => ['company_id' => $company->id, 'enabled' => (bool) $membership?->is_active, ...($client ? [] : [
                        'role_id' => $membership?->role_id, 'factory_id' => $membership?->factory_id,
                        'assignments' => app(CompanyContext::class)->run($company, fn () => MembershipAssignments::rows($membership))])],
                ];
            }),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $user)
    {
        return $this->withTarget($request, $user, fn (User $target) => $this->updateUser($request, $target));
    }

    private function updateUser(Request $request, User $user)
    {
        $this->assertTarget($user);
        $data = $request->validate([
            'access' => 'required|array|min:1', 'access.*.company_id' => 'required|integer|distinct',
            'access.*.enabled' => 'required|boolean', 'access.*.role_id' => 'nullable|integer',
            'access.*.factory_id' => 'nullable|integer', 'access.*.assignments' => 'sometimes|array|max:50',
        ]);
        DB::transaction(function () use ($request, $user, $data): void {
            // Lock the actor's memberships while rechecking destination rights.
            CompanyMembership::where('user_id', $request->user()->id)->orderBy('id')->lockForUpdate()->get();
            CompanyMembership::where('user_id', $user->id)->where('company_id', app(CompanyContext::class)->id())->lockForUpdate()->first();
            $companies = CompanyManagement::companies($request->user())->keyBy('id');
            abort_unless($companies->has(app(CompanyContext::class)->id()), 403, 'You can no longer manage the selected company.');
            app(CompanyContext::class)->forgetMembership($user->id);
            $this->assertTarget($user);
            $client = $this->isClient($user);
            foreach ($data['access'] as $row) {
                $company = $companies->get($row['company_id']);
                abort_unless($company, 403, 'You cannot manage access to this company.');
                $membership = CompanyMembership::where('company_id', $company->id)->where('user_id', $user->id)->lockForUpdate()->first();
                $unchanged = (bool) $membership?->is_active === (bool) $row['enabled']
                    && (int) $membership?->role_id === (int) ($row['role_id'] ?? null)
                    && (int) $membership?->factory_id === (int) ($row['factory_id'] ?? null);
                if ($unchanged && !array_key_exists('assignments', $row)) continue;
                $roleNames = app(CompanyContext::class)->run($company, fn () => MembershipAssignments::roleNames($membership));
                abort_if(!$request->user()->is_platform_admin && in_array('admin', $roleNames, true), 403, 'Administrator access is managed by the platform administrator.');
                abort_if($client && array_intersect($roleNames, MembershipAssignments::STAFF_ROLES), 403, 'Employee access must be edited from the employee workspace.');
                abort_if($company->id === app(CompanyContext::class)->id() && !$row['enabled'], 409, 'Remove access to this company through its employee or client form.');
                if (!$row['enabled']) {
                    $membership?->update(['is_active' => false]);
                    continue;
                }
                app(CompanyContext::class)->run($company, function () use ($row, $membership, $request, $user, $client): void {
                    if ($client && !empty($row['factory_id'])) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['access' => ['Client access does not include employee positions or workshops.']]);
                    }
                    if ($client && !isset($row['role_id']) && !array_key_exists('assignments', $row)) {
                        $row['role_id'] = Role::where('name', 'authenticatedUser')->value('id');
                    }
                    $assignments = MembershipAssignments::validate($row, $client ? ['authenticatedUser'] : $this->roles($request->user()));
                    $role = Role::findOrFail($assignments[0]['role_id']);
                    $resetGrants = $membership && (!$membership->is_active || (!isset($row['assignments']) && (int) $membership->role_id !== (int) $role->id));
                    $membership ??= new CompanyMembership(['company_id' => app(CompanyContext::class)->id(), 'user_id' => $user->id]);
                    if (!$membership->exists) $membership->fill([...$assignments[0], 'is_active' => true])->save();
                    else $membership->update(['is_active' => true]);
                    MembershipAssignments::sync($membership, $assignments, (bool) $resetGrants);
                    app(CompanyContext::class)->forgetMembership($user->id);
                    // Only shared identity is reused; each company's contacts and
                    // historical orders remain in that company's own profile.
                    if ($role->name === 'authenticatedUser') $user->client()->firstOrCreate([], ['name' => $user->name, 'last_name' => $user->last_name, 'phone' => '', 'type' => 'physPerson']);
                    else $user->worker()->firstOrCreate([], ['last_name' => $user->last_name, 'phone' => '']);
                });
            }
        }, 3);
        app(CompanyContext::class)->forgetMembership($user->id);
        return $this->showUser($request, $user);
    }

    private function withTarget(Request $request, string $id, \Closure $callback)
    {
        $data = $request->validate(['source_company_id' => 'nullable|integer']);
        $source = CompanyManagement::companies($request->user())->find($data['source_company_id'] ?? app(CompanyContext::class)->id());
        abort_unless($source, 403, 'You cannot manage access to this company.');
        return app(CompanyContext::class)->run($source, fn () => $callback(User::findOrFail($id)));
    }

    private function isClient(User $user): bool
    {
        return !array_intersect(MembershipAssignments::roleNames(app(CompanyContext::class)->membership($user->id)), MembershipAssignments::STAFF_ROLES);
    }

    private function roles(User $actor): array
    {
        return $actor->is_platform_admin ? [...self::ROLES, 'admin'] : self::ROLES;
    }

    private function assertTarget(User $user): void
    {
        abort_unless(app(CompanyContext::class)->membership($user->id)?->is_active, 404);
        abort_if($user->is_platform_admin, 422, 'Platform administrator access is managed separately.');
    }
}
