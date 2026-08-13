<?php

namespace App\Http\Controllers\Api\Materials;

use App\Http\Controllers\Controller;
use App\Models\Material;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MaterialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->input('per_page', 10), 100));
        $search = trim((string) $request->input('search', ''));
        $categoryId = $request->input('category_id');

        $q = Material::query()->orderByDesc('created_at');

        if ($categoryId) {
            $q->where('material_category_id', $categoryId);
        }

        if ($search !== '') {
            $q->where(function ($qq) use ($search) {
                $qq->where('description', 'like', "%{$search}%")
                    ->orWhereRaw('CAST(width AS CHAR) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('CAST(length AS CHAR) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('CAST(height AS CHAR) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('CAST(thickness AS CHAR) LIKE ?', ["%{$search}%"]);
            });
        }

        $p = $q->paginate($perPage);

        return response()->json([
            'data' => $p->items(),
            'pagination' => [
                'current_page' => $p->currentPage(),
                'last_page' => $p->lastPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'next_page_url' => $p->nextPageUrl(),
                'prev_page_url' => $p->previousPageUrl(),
            ],
        ]);
    }

    public function show(Material $material): JsonResponse
    {
        return response()->json($material);
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
            $data['image'] = $request->file('image')->store('materials', 'public');
        }

        $material = Material::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Material created successfully',
            'data' => $material,
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

        $oldImage = $material->image;
        $newImage = null;

        if ($request->hasFile('image')) {
            $newImage = $request->file('image')->store('materials', 'public');
            $data['image'] = $newImage;
        }

        try {
            $material->update($data);
        } catch (\Throwable $e) {
            if ($newImage && Storage::disk('public')->exists($newImage)) {
                Storage::disk('public')->delete($newImage);
            }
            throw $e;
        }

        if (
            $newImage &&
            $oldImage &&
            $oldImage !== $newImage &&
            Storage::disk('public')->exists($oldImage)
        ) {
            Storage::disk('public')->delete($oldImage);
        }

        return response()->json([
            'success' => true,
            'message' => 'Material updated successfully',
            'data' => $material->fresh(),
        ], 200);
    }

    public function destroy(Material $material): JsonResponse
    {
        $image = $material->image;
        $material->delete();

        if ($image && Storage::disk('public')->exists($image)) {
            Storage::disk('public')->delete($image);
        }

        return response()->json([
            'success' => true,
            'message' => 'Material deleted successfully',
        ], 200);
    }
}
