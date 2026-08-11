<?php

namespace App\Http\Controllers\Api\Materials;

use App\Http\Controllers\Controller;
use App\Models\MaterialCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = MaterialCategory::with('materials')->get();

        return response()->json($categories);
    }

    /**
     * Lightweight internal lookup for the material create/edit form. Having
     * materials.create/update is sufficient; category browsing remains a
     * separate permission and is not required just to populate a dropdown.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowed = $user?->role?->name === 'admin'
            || $user?->hasPermission('materials.create')
            || $user?->hasPermission('materials.update');

        abort_unless($allowed, 403, 'Forbidden');

        return response()->json([
            'categories' => MaterialCategory::query()
                ->orderBy('name')
                ->get(['id', 'name', 'material_group_id']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMutation($request, 'materials.create');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'material_group_id' => 'required|exists:material_groups,id',
        ]);

        $category = MaterialCategory::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Material category created successfully',
            'data' => $category,
        ], 201);
    }

    public function show(MaterialCategory $materialCategory): JsonResponse
    {
        $materialCategory->load('materials');

        return response()->json([
            'success' => true,
            'data' => $materialCategory,
        ], 200);
    }

    public function update(Request $request, MaterialCategory $materialCategory): JsonResponse
    {
        $this->authorizeMutation($request, 'materials.update');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'material_group_id' => 'required|exists:material_groups,id',
        ]);

        $materialCategory->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Material category updated successfully',
            'data' => $materialCategory->fresh(),
        ], 200);
    }

    public function destroy(Request $request, MaterialCategory $materialCategory): JsonResponse
    {
        $this->authorizeMutation($request, 'materials.delete');

        $materialCategory->delete();

        return response()->json([
            'success' => true,
            'message' => 'Material category deleted successfully',
        ], 200);
    }

    private function authorizeMutation(Request $request, string $permission): void
    {
        $user = $request->user('sanctum');

        abort_unless($user, 401, 'Unauthenticated');

        if ($user->role?->name === 'admin') {
            return;
        }

        abort_unless(
            method_exists($user, 'hasPermission') && $user->hasPermission($permission),
            403,
            'Forbidden'
        );
    }
}
