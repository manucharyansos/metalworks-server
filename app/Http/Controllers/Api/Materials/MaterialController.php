<?php

namespace App\Http\Controllers\Api\Materials;

use App\Http\Controllers\Controller;
use App\Models\Material;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MaterialController extends Controller
{
    public function index(): JsonResponse
    {
        $materials = Material::paginate();

        return response()->json($materials);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => 'nullable|string',
            'width' => 'nullable|numeric',
            'length' => 'nullable|numeric',
            'thickness' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'image' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'material_category_id' => 'required|exists:material_categories,id',
        ]);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $uniqueName = uniqid() . '_' . $file->getClientOriginalName();
            $data['image'] = $file->storeAs('materials', $uniqueName, 'public');
        }

        $material = Material::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Material created successfully',
            'data' => $material
        ], 201);
    }


    public function update(Request $request, Material $material): JsonResponse
    {
        $data = $request->validate([
            'description' => 'nullable|string',
            'width' => 'nullable|numeric',
            'length' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'thickness' => 'nullable|numeric',
            'image' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'material_category_id' => 'required|exists:material_categories,id',
        ]);

        if ($request->hasFile('image')) {
            if ($material->image && Storage::disk('public')->exists($material->image)) {
                Storage::disk('public')->delete($material->image);
            }
            $file = $request->file('image');
            $uniqueName = uniqid() . '_' . $file->getClientOriginalName();
            $data['image'] = $file->storeAs('materials', $uniqueName, 'public');
        }

        $material->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Material updated successfully',
            'data' => $material
        ], 200);
    }


    public function destroy(Material $material): JsonResponse
    {
        if ($material->image && Storage::disk('public')->exists($material->image)) {
            Storage::disk('public')->delete($material->image);
        }

        $material->delete();

        return response()->json([
            'success' => true,
            'message' => 'Material deleted successfully'
        ], 200);
    }
}
