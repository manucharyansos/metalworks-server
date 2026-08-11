<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UserPermissionController extends Controller
{
    /**
     * GET /api/users/{user}/permissions
     */
    public function show(User $user): JsonResponse
    {
        $this->ensureStaffAccount($user);
        $user->load(['role.permissions', 'permissions']);

        $allPermissions = Permission::query()
            ->orderBy('group')
            ->orderBy('slug')
            ->get();

        $rolePermissionIds = $user->role
            ? $user->role->permissions->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        [$allowedPermissionIds, $deniedPermissionIds] = $this->userOverrides($user);
        $isAdmin = $user->role?->name === 'admin';

        $permissions = $allPermissions->map(function (Permission $permission) use (
            $rolePermissionIds,
            $allowedPermissionIds,
            $deniedPermissionIds,
            $isAdmin
        ) {
            $id = (int) $permission->id;
            $viaRole = in_array($id, $rolePermissionIds, true);
            $explicitlyAllowed = in_array($id, $allowedPermissionIds, true);
            $explicitlyDenied = in_array($id, $deniedPermissionIds, true);

            $override = $explicitlyDenied
                ? 'deny'
                : ($explicitlyAllowed ? 'allow' : 'inherit');

            $effective = $isAdmin
                || (!$explicitlyDenied && ($explicitlyAllowed || $viaRole));

            return [
                'id' => $id,
                'name' => $permission->name,
                'slug' => $permission->slug,
                'group' => $permission->group,
                'via_role' => $viaRole,
                'override' => $override,
                'effective' => $effective,
            ];
        });

        return response()->json([
            'user' => $user,
            'permissions' => $permissions,
            'user_permission_ids' => $allowedPermissionIds,
            'user_denied_permission_ids' => $deniedPermissionIds,
            'role_permission_ids' => $rolePermissionIds,
            'overrides_supported' => $this->overridesSupported(),
            'immutable_full_access' => $isAdmin,
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureStaffAccount($user);
        $user->loadMissing('role');

        if ($user->role?->name === 'admin') {
            return response()->json([
                'message' => 'Admin role-ը միշտ ունի լիարժեք մուտք և անհատական սահմանափակում չի ընդունում։',
            ], 422);
        }

        if (!$this->overridesSupported()) {
            return response()->json([
                'message' => 'Permission override migration-ը դեռ կիրառված չէ։',
            ], 409);
        }

        $data = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['integer', 'distinct', 'exists:permissions,id'],
            'denied_permissions' => ['sometimes', 'array'],
            'denied_permissions.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        $allowedIds = array_values(array_unique(array_map('intval', $data['permissions'] ?? [])));
        $deniedIds = array_values(array_unique(array_map('intval', $data['denied_permissions'] ?? [])));
        $overlap = array_values(array_intersect($allowedIds, $deniedIds));

        if ($overlap !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['Նույն permission-ը չի կարող միաժամանակ թույլատրված և արգելված լինել։'],
            ]);
        }

        $syncData = [];

        foreach ($allowedIds as $permissionId) {
            $syncData[$permissionId] = ['allowed' => true];
        }

        foreach ($deniedIds as $permissionId) {
            $syncData[$permissionId] = ['allowed' => false];
        }

        DB::transaction(function () use ($user, $syncData) {
            $user->permissions()->sync($syncData);
        });

        return response()->json([
            'message' => 'Աշխատակցի թույլտվությունները հաջողությամբ թարմացվեցին։',
        ]);
    }

    /**
     * @return array{0: array<int>, 1: array<int>}
     */
    private function userOverrides(User $user): array
    {
        if (!$this->overridesSupported()) {
            return [
                $user->permissions->pluck('id')->map(fn ($id) => (int) $id)->all(),
                [],
            ];
        }

        $rows = DB::table('permission_user')
            ->where('user_id', $user->id)
            ->get(['permission_id', 'allowed']);

        $allowed = [];
        $denied = [];

        foreach ($rows as $row) {
            if ((bool) $row->allowed) {
                $allowed[] = (int) $row->permission_id;
            } else {
                $denied[] = (int) $row->permission_id;
            }
        }

        return [$allowed, $denied];
    }

    private function ensureStaffAccount(User $user): void
    {
        $user->loadMissing(['role', 'client']);

        abort_if(
            $user->client !== null || $user->role?->name === 'authenticatedUser',
            404,
            'Staff account not found.'
        );
    }

    private function overridesSupported(): bool
    {
        return Schema::hasColumn('permission_user', 'allowed');
    }
}
