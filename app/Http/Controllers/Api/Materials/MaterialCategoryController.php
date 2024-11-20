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
        $categories = MaterialCategory::with('type')->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'material_type_id' => 'required|exists:material_types,id',
        ]);

        $category = MaterialCategory::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Material category created successfully',
            'data' => $category
        ], 201);
    }

    public function show(MaterialCategory $materialCategory)
    {
        $materialCategory->load('type');

        return response()->json([
            'success' => true,
            'data' => $materialCategory
        ], 200);
    }

    public function update(Request $request, MaterialCategory $materialCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'material_type_id' => 'required|exists:material_types,id',
        ]);

        $materialCategory->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Material category updated successfully',
            'data' => $materialCategory
        ], 200);
    }

    public function destroy(MaterialCategory $materialCategory): JsonResponse
    {
        $materialCategory->delete();

        return response()->json([
            'success' => true,
            'message' => 'Material category deleted successfully'
        ], 200);
    }
}
