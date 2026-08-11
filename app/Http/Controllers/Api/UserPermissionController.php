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
     *
     * Permissions are independent from roles. The admin sees only concrete
     * business functions; role lookup itself is intentionally not configurable.
     */
    public function show(User $user): JsonResponse
    {
        $this->ensureStaffAccount($user);
        $user->loadMissing('role');

        $permissions = $this->assignablePermissions()->get();
        $allowedIds = $permissions->pluck('id')->map(fn ($id) => (int) $id)->all();

        $selectedIds = [];
        if ($this->assignmentsSupported() && $allowedIds !== []) {
            $selectedIds = DB::table('permission_user')
                ->where('user_id', $user->id)
                ->where('allowed', true)
                ->whereIn('permission_id', $allowedIds)
                ->pluck('permission_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        return response()->json([
            'user' => $user,
            'permissions' => $permissions,
            'user_permission_ids' => $selectedIds,
            'assignments_supported' => $this->assignmentsSupported(),
            'immutable_full_access' => $user->role?->name === 'admin',
            'role_permissions_used' => false,
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureStaffAccount($user);
        $user->loadMissing('role');

        if ($user->role?->name === 'admin') {
            return response()->json([
                'message' => 'Admin-ը միշտ ունի լիարժեք մուտք և անհատական սահմանափակում չի ընդունում։',
            ], 422);
        }

        if (!$this->assignmentsSupported()) {
            return response()->json([
                'message' => 'Individual permission migration-ը դեռ կիրառված չէ։',
            ], 409);
        }

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        $permissionIds = array_values(array_unique(array_map('intval', $data['permissions'])));
        $assignableIds = $this->assignablePermissions()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $invalidIds = array_values(array_diff($permissionIds, $assignableIds));
        if ($invalidIds !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['Չկառավարվող կամ ներքին permission փոխանցել չի թույլատրվում։'],
            ]);
        }

        DB::transaction(function () use ($user, $permissionIds, $assignableIds) {
            // Remove only assignable business-function grants. Internal lookup
            // behavior is not stored in permission_user.
            if ($assignableIds !== []) {
                DB::table('permission_user')
                    ->where('user_id', $user->id)
                    ->whereIn('permission_id', $assignableIds)
                    ->delete();
            }

            if ($permissionIds === []) {
                return;
            }

            $now = now();
            $rows = array_map(
                fn (int $permissionId): array => [
                    'user_id' => $user->id,
                    'permission_id' => $permissionId,
                    'allowed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $permissionIds
            );

            DB::table('permission_user')->insert($rows);
        });

        return response()->json([
            'message' => 'Աշխատակցի թույլտվությունները պահպանվեցին։',
            'permission_ids' => $permissionIds,
        ]);
    }

    private function assignablePermissions()
    {
        return Permission::query()
            ->where('slug', '!=', 'roles.view')
            ->orderBy('group')
            ->orderBy('slug')
            ->select(['id', 'name', 'slug', 'group']);
    }

    private function ensureStaffAccount(User $user): void
    {
        $user->loadMissing(['role', 'client']);

        abort_if(
            $user->client !== null
                || $user->role === null
                || in_array($user->role->name, ['authenticatedUser', 'guestUser'], true),
            404,
            'Staff account not found.'
        );
    }

    private function assignmentsSupported(): bool
    {
        return Schema::hasTable('permission_user')
            && Schema::hasColumn('permission_user', 'allowed');
    }
}
