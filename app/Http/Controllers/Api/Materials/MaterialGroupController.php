<?php

namespace App\Http\Controllers\Api\Materials;

use App\Http\Controllers\Controller;
use App\Models\MaterialGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MaterialGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $materialGroups = MaterialGroup::with('categories.materials')->get();
        return response()->json($materialGroups, 200);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        ]);
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $uniqueName = uniqid() . '_' . $file->getClientOriginalName();
            $data['image'] = $file->storeAs('categories', $uniqueName, 'public');
        }

        $materialGroups = MaterialGroup::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Material group created successfully',
            $materialGroups
        ], 201);
    }

    public function show(MaterialGroup $materialGroups): JsonResponse
    {
        $materialGroups->load('categories');

        return response()->json([
            'success' => true,
            'data' => $materialGroups
        ], 200);
    }

    public function update(Request $request, MaterialGroup $materialGroups): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        ]);
        if ($request->hasFile('image')) {
            if ($materialGroups->image && Storage::disk('public')->exists($materialGroups->image)) {
                Storage::disk('public')->delete($materialGroups->image);
            }
            $file = $request->file('image');
            $uniqueName = uniqid() . '_' . $file->getClientOriginalName();
            $data['image'] = $file->storeAs('categories', $uniqueName, 'public');
        }
        $materialGroups->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Material group updated successfully',
            'data' => $materialGroups
        ], 200);
    }

    public function destroy(MaterialGroup $materialGroups): JsonResponse
    {
        $materialGroups->delete();

        return response()->json([
            'success' => true,
            'message' => 'Material group deleted successfully'
        ], 200);
    }
}
