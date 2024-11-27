<?php

namespace App\Http\Controllers\Api\Materials;

use App\Http\Controllers\Controller;
use App\Models\MaterialType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $materialTypes = MaterialType::with('categories')->get();
        return response()->json($materialTypes, 200);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $materialType = MaterialType::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Material type created successfully',
            $materialType
        ], 201);
    }

    public function show(MaterialType $materialType): JsonResponse
    {
        $materialType->load('categories');

        return response()->json([
            'success' => true,
            'data' => $materialType
        ], 200);
    }

    public function update(Request $request, MaterialType $materialType): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $materialType->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Material type updated successfully',
            'data' => $materialType
        ], 200);
    }

    public function destroy(MaterialType $materialType): JsonResponse
    {
        $materialType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Material type deleted successfully'
        ], 200);
    }
}
