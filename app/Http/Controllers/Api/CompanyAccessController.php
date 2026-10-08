<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Company, CompanyMembership, Factory, Role, User};
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\Rule;

class CompanyAccessController extends Controller
{
    private const ROLES = ['authenticatedUser', 'manager', 'engineer', 'laser', 'bend', 'powder_catting'];
    private const OPERATORS = ['laser', 'bend', 'powder_catting'];

    public function show(Request $request, User $user)
    {
        $this->assertTarget($user);
        $companies = $this->manageableCompanies($request->user());
        $memberships = CompanyMembership::where('user_id', $user->id)->whereIn('company_id', $companies->modelKeys())->get()->keyBy('company_id');
        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'current_company_id' => app(CompanyContext::class)->id(),
            'roles' => Role::whereIn('name', $this->roles($request->user()))->orderBy('name')->get(['id', 'name', 'value']),
            'companies' => $companies->map(function (Company $company) use ($memberships, $request) {
                $membership = $memberships->get($company->id);
                $readOnly = $company->id === app(CompanyContext::class)->id()
                    || (!$request->user()->is_platform_admin && $membership?->role?->name === 'admin');
                return [
                    'id' => $company->id, 'name' => $company->name, 'read_only' => $readOnly,
                    'factories' => Factory::withoutGlobalScope('company')->where('company_id', $company->id)->orderBy('name')->get(['id', 'name']),
                    'access' => ['company_id' => $company->id, 'enabled' => (bool) $membership?->is_active,
                        'role_id' => $membership?->role_id, 'factory_id' => $membership?->factory_id],
                ];
            }),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, User $user)
    {
        $this->assertTarget($user);
        $data = $request->validate([
            'access' => 'required|array|min:1', 'access.*.company_id' => 'required|integer|distinct',
            'access.*.enabled' => 'required|boolean', 'access.*.role_id' => 'nullable|integer',
            'access.*.factory_id' => 'nullable|integer',
        ]);
        DB::transaction(function () use ($request, $user, $data): void {
            // Lock the actor's memberships while rechecking destination rights.
            CompanyMembership::where('user_id', $request->user()->id)->orderBy('id')->lockForUpdate()->get();
            CompanyMembership::where('user_id', $user->id)->where('company_id', app(CompanyContext::class)->id())->lockForUpdate()->first();
            $companies = $this->manageableCompanies($request->user())->keyBy('id');
            abort_unless($companies->has(app(CompanyContext::class)->id()), 403, 'You can no longer manage the selected company.');
            app(CompanyContext::class)->forgetMembership($user->id);
            $this->assertTarget($user);
            foreach ($data['access'] as $row) {
                $company = $companies->get($row['company_id']);
                abort_unless($company, 403, 'You cannot manage access to this company.');
                $membership = CompanyMembership::where('company_id', $company->id)->where('user_id', $user->id)->lockForUpdate()->first();
                $unchanged = (bool) $membership?->is_active === (bool) $row['enabled']
                    && (int) $membership?->role_id === (int) ($row['role_id'] ?? null)
                    && (int) $membership?->factory_id === (int) ($row['factory_id'] ?? null);
                if ($unchanged) continue;
                abort_if($company->id === app(CompanyContext::class)->id(), 409, 'Edit the selected company through its employee or client form.');
                abort_if(!$request->user()->is_platform_admin && $membership?->role?->name === 'admin', 403, 'Administrator access is managed by the platform administrator.');
                if (!$row['enabled']) {
                    $membership?->update(['is_active' => false]);
                    continue;
                }
                app(CompanyContext::class)->run($company, function () use ($row, $membership, $request, $user): void {
                    $validated = Validator::make($row, [
                        'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereIn('name', $this->roles($request->user())))],
                        'factory_id' => 'nullable|integer|exists:factories,id',
                    ])->validate();
                    $role = Role::findOrFail($validated['role_id']);
                    $operator = in_array($role->name, self::OPERATORS, true);
                    Validator::make($validated, ['factory_id' => $operator ? 'required|integer|exists:factories,id' : 'nullable'])->validate();
                    $resetGrants = $membership && (!$membership->is_active || (int) $membership->role_id !== (int) $role->id);
                    $membership ??= new CompanyMembership(['company_id' => app(CompanyContext::class)->id(), 'user_id' => $user->id]);
                    $membership->fill(['is_active' => true, 'role_id' => $role->id, 'factory_id' => $operator ? $validated['factory_id'] : null])->save();
                    if ($resetGrants) $membership->permissions()->detach();
                    app(CompanyContext::class)->forgetMembership($user->id);
                    // Only shared identity is reused; each company's contacts and
                    // historical orders remain in that company's own profile.
                    if ($role->name === 'authenticatedUser') $user->client()->firstOrCreate([], ['name' => $user->name, 'last_name' => $user->last_name, 'phone' => '', 'type' => 'physPerson']);
                    else $user->worker()->firstOrCreate([], ['last_name' => $user->last_name, 'phone' => '']);
                });
            }
        }, 3);
        app(CompanyContext::class)->forgetMembership($user->id);
        return $this->show($request, $user);
    }

    private function manageableCompanies(User $actor)
    {
        $query = Company::where('is_active', true)->orderBy('id');
        if (!$actor->is_platform_admin) $query->whereHas('memberships', fn ($q) => $q->where('user_id', $actor->id)->where('is_active', true)
            ->whereHas('role', fn ($roles) => $roles->whereIn('name', ['admin', 'manager'])));
        return $query->get();
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
