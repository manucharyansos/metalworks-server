<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPermissionController extends Controller
{
    /**
     * GET /api/users/{user}/permissions
     */
    public function show(User $user): JsonResponse
    {
        $user->load(['role.permissions', 'permissions']);

        $allPermissions = Permission::orderBy('group')
            ->orderBy('slug')
            ->get();

        $rolePermissionIds = $user->role
            ? $user->role->permissions->pluck('id')->toArray()
            : [];

        $userPermissionIds = $user->permissions->pluck('id')->toArray();

        $permissions = $allPermissions->map(function (Permission $perm) use (
            $rolePermissionIds,
            $userPermissionIds
        ) {
            $id = $perm->id;

            $viaRole = in_array($id, $rolePermissionIds, true);
            $viaUser = in_array($id, $userPermissionIds, true);

            return [
                'id'        => $id,
                'name'      => $perm->name,
                'slug'      => $perm->slug,
                'group'     => $perm->group,
                'via_role'  => $viaRole,
                'via_user'  => $viaUser,
                'effective' => $viaRole || $viaUser,
            ];
        });

        return response()->json([
            'user'                => $user,
            'permissions'         => $permissions,
            'user_permission_ids' => $userPermissionIds,
            'role_permission_ids' => $rolePermissionIds,
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'permissions'   => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $ids = $data['permissions'] ?? [];

        $user->permissions()->sync($ids);

        return response()->json([
            'message' => 'Թույլտվությունները հաջողությամբ թարմացվեցին։',
        ]);
    }

}
