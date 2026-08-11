<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserPermissionController extends Controller
{
    /**
     * GET /api/users/{user}/permissions
     *
     * Permissions are intentionally independent from roles. The admin sees
     * the same permission catalogue for every staff account and explicitly
     * chooses which concrete functions are enabled for that employee.
     */
    public function show(User $user): JsonResponse
    {
        $this->ensureStaffAccount($user);
        $user->loadMissing('role');

        $permissions = Permission::query()
            ->orderBy('group')
            ->orderBy('slug')
            ->get(['id', 'name', 'slug', 'group']);

        $selectedIds = [];
        if ($this->assignmentsSupported()) {
            $selectedIds = DB::table('permission_user')
                ->where('user_id', $user->id)
                ->where('allowed', true)
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

        DB::transaction(function () use ($user, $permissionIds) {
            DB::table('permission_user')
                ->where('user_id', $user->id)
                ->delete();

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
