<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use App\Support\PermissionMap;
use App\Support\PermissionScope;
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
     * Admin and manager can manage individual employee permissions. The list
     * returned here is already restricted to functions that can actually be
     * used by the target employee's role.
     */
    public function show(User $user): JsonResponse
    {
        $this->ensureStaffAccount($user);
        $user->loadMissing('role');

        $scope = PermissionScope::forRoles($user->workRoleNames());
        $permissions = $this->assignablePermissions($user)->get();

        // Always display the canonical business label from config, even when an
        // older database row still contains a legacy/duplicated title.
        $permissions->each(function (Permission $permission) {
            $label = PermissionMap::label($permission->slug);
            if ($label) {
                $permission->name = $label;
            }
        });

        $allowedIds = $permissions
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $selectedIds = [];
        if ($this->assignmentsSupported() && $allowedIds !== []) {
            $selectedIds = DB::table('membership_permissions')
                ->where('membership_id', $user->current_membership_id)
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
            'immutable_full_access' => (bool) $scope['full_access'],
            'role_permissions_used' => false,
            'permission_scope' => [
                'role' => $scope['role'],
                'roles' => $scope['roles'],
                'full_access' => (bool) $scope['full_access'],
                'groups' => $scope['groups'],
                'default_group' => $scope['default_group'],
                'allowed_slugs' => $scope['permissions'],
                'dependencies' => $scope['dependencies'] ?? [],
            ],
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureStaffAccount($user);
        $user->loadMissing('role');

        $scope = PermissionScope::forRoles($user->workRoleNames());

        if ($scope['full_access']) {
            return response()->json([
                'message' => 'Admin և Manager հաստիքները միշտ ունեն լիարժեք հասանելիություն և անհատական սահմանափակում չեն ընդունում։',
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

        $requestedPermissionIds = array_values(array_unique(array_map('intval', $data['permissions'])));
        $assignablePermissions = $this->assignablePermissions($user)->get();
        $assignableIds = $assignablePermissions
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $invalidIds = array_values(array_diff($requestedPermissionIds, $assignableIds));
        if ($invalidIds !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['Ընտրված թույլտվություններից մեկը չի վերաբերում այս աշխատակցի հաստիքին։'],
            ]);
        }

        $requestedSlugs = $assignablePermissions
            ->whereIn('id', $requestedPermissionIds)
            ->pluck('slug')
            ->values()
            ->all();

        $expandedSlugs = PermissionScope::expandForRoles(
            $user->workRoleNames(),
            $requestedSlugs
        );

        $permissionIds = $assignablePermissions
            ->whereIn('slug', $expandedSlugs)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $catalogPermissionIds = Permission::query()
            ->whereIn('slug', PermissionMap::allSlugs())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        DB::transaction(function () use ($user, $permissionIds, $catalogPermissionIds) {
            // Clear all known business grants first. This also removes stale
            // permissions left behind after an employee changes role.
            if ($catalogPermissionIds !== []) {
                DB::table('membership_permissions')
                    ->where('membership_id', $user->current_membership_id)
                    ->whereIn('permission_id', $catalogPermissionIds)
                    ->delete();
            }

            if ($permissionIds === []) {
                return;
            }

            $now = now();
            $rows = array_map(
                fn (int $permissionId): array => [
                    'membership_id' => $user->current_membership_id,
                    'permission_id' => $permissionId,
                    'allowed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $permissionIds
            );

            DB::table('membership_permissions')->insert($rows);
        });

        // Pivot-table changes do not fire Eloquent model observers, so record
        // this business-critical action explicitly without storing secrets.
        if (Schema::hasTable('activity_logs')) {
            try {
                ActivityLog::create([
                    'user_id' => $request->user()?->id,
                    'category' => 'access',
                    'action' => 'access.permissions_updated',
                    'method' => $request->method(),
                    'route' => $request->path(),
                    'subject_type' => 'user',
                    'subject_id' => (string) $user->id,
                    'subject_label' => $user->name,
                    'description' => null,
                    'meta' => [
                        'target_user_id' => $user->id,
                        'target_role' => $user->role?->name,
                        'permissions' => array_values($expandedSlugs),
                    ],
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => 'Աշխատակցի թույլտվությունները պահպանվեցին։',
            'permission_ids' => $permissionIds,
        ]);
    }

    private function assignablePermissions(User $user)
    {
        $scope = PermissionScope::forRoles($user->workRoleNames());

        if ($scope['permissions'] === []) {
            return Permission::query()->whereRaw('1 = 0');
        }

        return Permission::query()
            ->whereIn('slug', $scope['permissions'])
            ->orderBy('group')
            ->orderBy('slug')
            ->select(['id', 'name', 'slug', 'group']);
    }

    private function ensureStaffAccount(User $user): void
    {
        abort_unless(app(\App\Support\CompanyContext::class)->membership($user->id)?->is_active, 404);
        $user->loadMissing(['role', 'client']);

        abort_if(
            !array_intersect($user->workRoleNames(), \App\Support\MembershipAssignments::STAFF_ROLES),
            404,
            'Staff account not found.'
        );
    }

    private function assignmentsSupported(): bool
    {
        return Schema::hasTable('membership_permissions')
            && Schema::hasColumn('membership_permissions', 'allowed');
    }
}
