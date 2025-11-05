<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Support\PermissionMap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);
        $search  = trim($request->get('search', ''));

        $query = Permission::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('group', 'like', "%{$search}%");
            });
        }

        $permissions = $query
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($permissions);
    }

    public function show(Permission $permission)
    {
        return response()->json($permission);
    }

    public function store(Request $request)
    {
        $validSlugs = PermissionMap::allSlugs();

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'slug'  => [
                'required',
                'string',
                'max:255',
                'unique:permissions,slug',
                Rule::in($validSlugs),
            ],
            'group' => ['nullable', 'string', 'max:255'],
        ]);

        $permission = Permission::create($data);

        return response()->json([
            'message' => 'Թույլտվությունը հաջողությամբ ստեղծվեց։',
            'data'    => $permission,
        ], 201);
    }

    public function update(Request $request, Permission $permission)
    {
        $validSlugs = PermissionMap::allSlugs();

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'slug'  => [
                'required',
                'string',
                'max:255',
                Rule::unique('permissions', 'slug')->ignore($permission->id),
                Rule::in($validSlugs),
            ],
            'group' => ['nullable', 'string', 'max:255'],
        ]);

        $permission->update($data);

        return response()->json([
            'message' => 'Թույլտվությունը հաջողությամբ թարմացվեց։',
            'data'    => $permission,
        ]);
    }

    public function destroy(Permission $permission)
    {
        $permission->roles()->detach();
        $permission->users()->detach();
        $permission->delete();

        return response()->json([
            'message' => 'Թույլտվությունը հաջողությամբ ջնջվեց։',
        ]);
    }
}
