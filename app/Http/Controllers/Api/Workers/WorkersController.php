<?php

namespace App\Http\Controllers\Api\Workers;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkerResource;
use App\Models\Factory;
use App\Models\CompanyMembership;
use App\Support\CompanyContext;
use App\Support\CompanyStaffAccess;
use App\Support\CompanyManagement;
use App\Support\MembershipAssignments;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkersController extends Controller
{
    private const WORKER_ROLE_NAMES = [
        'admin',
        'manager',
        'bend',
        'laser',
        'powder_catting',
        'engineer',
    ];

    private const FACTORY_ROLE_NAMES = [
        'bend',
        'laser',
        'powder_catting',
    ];

    public function index(): AnonymousResourceCollection
    {
        $workers = User::with(['worker', 'role', 'factory'])
            ->forRoles(self::WORKER_ROLE_NAMES)
            ->orderByDesc('id')
            ->get();

        return WorkerResource::collection($workers);
    }

    /**
     * Read-only form options for staff creation/editing. Admin and manager are
     * privileged; other roles need the matching worker mutation permission.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user?->role?->name;
        $allowed = in_array($role, ['admin', 'manager'], true)
            || $user?->hasPermission('workers.create')
            || $user?->hasPermission('workers.update');

        abort_unless($allowed, 403, 'Forbidden');

        $companies = in_array($role, ['admin', 'manager'], true) ? CompanyManagement::companies($user) : new \Illuminate\Database\Eloquent\Collection();

        return response()->json([
            'roles' => Role::query()
                ->whereIn('name', $user->is_platform_admin ? self::WORKER_ROLE_NAMES : array_diff(self::WORKER_ROLE_NAMES, ['admin']))
                ->orderBy('name')
                ->get(['id', 'name', 'value']),
            'can_manage_companies' => $companies->isNotEmpty(),
            'companies' => $companies->map(fn ($c) => [
                ...$c->summary(),
                'factories' => Factory::withoutGlobalScope('company')->where('company_id', $c->id)->get(['id', 'name', 'value']),
            ]),
            'factories' => Factory::query()
                ->orderBy('name')
                ->get(['id', 'name', 'value']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $existing = User::withoutGlobalScope('company')->where('email', $request->email)->first();
        if ($existing && !$request->user()->is_platform_admin) throw ValidationException::withMessages(['email' => ['This email is already registered. Ask the platform administrator to add company access.']]);
        if ($existing && CompanyMembership::where('company_id', app(CompanyContext::class)->id())->where('user_id', $existing->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['email' => ['This account already has access to the selected company.']]);
        }
        $validated = $this->validateWorker($request, $existing, true);
        $factoryId = $this->validatedFactoryIdForRole((int) $validated['role_id'], $validated['factory_id'] ?? null);
        $user = DB::transaction(function () use ($request, $existing, $validated, $factoryId) {
            $user = $existing ?: User::create([
                'name' => $validated['name'], 'last_name' => $validated['last_name'] ?? null,
                'email' => $validated['email'], 'password' => $validated['password'],
                'role_id' => $validated['role_id'], 'factory_id' => $factoryId,
            ]);
            CompanyMembership::updateOrCreate(['company_id' => app(CompanyContext::class)->id(), 'user_id' => $user->id], [
                'role_id' => $validated['role_id'], 'factory_id' => $factoryId, 'is_active' => true,
            ]);
            MembershipAssignments::sync(app(CompanyContext::class)->membership($user->id), $validated['assignments'], (bool) $existing);
            app(CompanyContext::class)->forgetMembership($user->id);
            $user->worker()->updateOrCreate([], $this->contactData($validated));
            CompanyStaffAccess::sync($request, $user);
            return $user;
        });
        return response()->json(new WorkerResource($user->load(['worker', 'role', 'factory'])), 201);
    }

    public function show(User $worker): WorkerResource
    {
        $this->assertWorkerTarget($worker);
        return new WorkerResource($worker->load('worker', 'role', 'factory'));
    }

    public function update(Request $request, User $worker): JsonResponse
    {
        $this->assertWorkerTarget($worker);
        abort_if($worker->is_platform_admin && !$request->user()->is_platform_admin, 403);
        abort_if(!$request->user()->is_platform_admin && in_array('admin', $worker->workRoleNames(), true), 403);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $validated = $this->validateWorker($request, $worker, false);
        $factoryId = $this->validatedFactoryIdForRole((int) $validated['role_id'], $validated['factory_id'] ?? null);
        $canEditAccount = $request->user()->is_platform_admin || $worker->memberships()->where('is_active', true)->count() <= 1;
        if (!$canEditAccount && ($validated['name'] !== $worker->name || $validated['email'] !== $worker->email || !empty($validated['password']))) {
            throw ValidationException::withMessages(['email' => ['Shared account details must be changed by the user or platform administrator.']]);
        }
        DB::transaction(function () use ($request, $worker, $validated, $factoryId, $canEditAccount) {
            if ($canEditAccount) {
                $worker->update(['name' => $validated['name'], 'email' => $validated['email']]);
                if (!empty($validated['password'])) $worker->update(['password' => $validated['password']]);
            }
            $membership = app(CompanyContext::class)->membership($worker->id);
            $roleChanged = (int) $membership->role_id !== (int) $validated['role_id'];
            MembershipAssignments::sync($membership, $validated['assignments'], !$request->has('assignments') && $roleChanged);
            app(CompanyContext::class)->forgetMembership($worker->id);
            $worker->worker()->updateOrCreate([], $this->contactData($validated));
            CompanyStaffAccess::sync($request, $worker);
        });
        $worker->unsetRelation('role')->unsetRelation('factory')->unsetRelation('worker');
        return response()->json(['message' => 'Աշխատակիցը հաջողությամբ թարմացվեց', 'data' => new WorkerResource($worker->load(['worker', 'role', 'factory']))]);
    }

    public function destroy(User $worker): JsonResponse
    {
        $this->assertWorkerTarget($worker);
        abort_if($worker->is_platform_admin || $worker->id === request()->user()->id, 422, 'Cannot revoke this administrator through the employee form.');
        abort_if(!request()->user()->is_platform_admin && in_array('admin', $worker->workRoleNames(), true), 403);
        app(CompanyContext::class)->membership($worker->id)->update(['is_active' => false]);
        app(CompanyContext::class)->forgetMembership($worker->id);
        return response()->json(['message' => 'Այս կազմակերպության հասանելիությունը փակվեց։']);
    }

    private function validateWorker(Request $request, ?User $user, bool $creating): array
    {
        $roles = $request->user()->is_platform_admin ? self::WORKER_ROLE_NAMES : array_diff(self::WORKER_ROLE_NAMES, ['admin']);
        $rules = [
            'name' => 'required|string|max:255', 'last_name' => 'nullable|string|max:255',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => ($creating && !$user ? 'required' : 'nullable') . '|string|min:8|confirmed',
            'phone' => 'required|string|max:20',
            'second_phone' => 'nullable|string|max:20', 'address' => 'nullable|string|max:255',
        ];
        if ($request->has('company_access')) abort_unless(in_array($request->user()->role?->name, ['admin', 'manager'], true), 403);
        $validated = $request->validate($rules);
        $assignments = MembershipAssignments::validate($request->all(), $roles);
        return [...$validated, ...$assignments[0], 'assignments' => $assignments];
    }

    private function contactData(array $data): array
    {
        return collect($data)->only(['last_name', 'phone', 'second_phone', 'address'])->all();
    }

    private function assertWorkerTarget(User $worker): void
    {
        abort_unless(app(CompanyContext::class)->membership($worker->id)?->is_active, 404);
        $worker->loadMissing('role');

        abort_unless(
            $worker->role && in_array($worker->role->name, self::WORKER_ROLE_NAMES, true),
            404,
            'Worker not found'
        );
    }

    private function validatedFactoryIdForRole(int $roleId, mixed $factoryId): ?int
    {
        $roleName = Role::whereKey($roleId)->value('name');

        if (in_array($roleName, self::FACTORY_ROLE_NAMES, true)) {
            if (!$factoryId) {
                throw ValidationException::withMessages([
                    'factory_id' => ['Այս աշխատակցի դերի համար արտադրամասը պարտադիր է։'],
                ]);
            }

            return (int) $factoryId;
        }

        return null;
    }
}
