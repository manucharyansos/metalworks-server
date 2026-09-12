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
        $this->authorizeMutation($request, 'materials.create');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $uniqueName = uniqid('', true) . '_' . basename($file->getClientOriginalName());
            $data['image'] = $file->storeAs('categories', $uniqueName, 'public');
        }

        $materialGroup = MaterialGroup::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Material group created successfully',
            'data' => $materialGroup,
        ], 201);
    }

    public function show(MaterialGroup $materialGroup): JsonResponse
    {
        $materialGroup->load('categories');

        return response()->json([
            'success' => true,
            'data' => $materialGroup,
        ], 200);
    }

    public function update(Request $request, MaterialGroup $materialGroup): JsonResponse
    {
        $this->authorizeMutation($request, 'materials.update');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->hasFile('image')) {
            if ($materialGroup->image && Storage::disk('public')->exists($materialGroup->image)) {
                Storage::disk('public')->delete($materialGroup->image);
            }

            $file = $request->file('image');
            $uniqueName = uniqid('', true) . '_' . basename($file->getClientOriginalName());
            $data['image'] = $file->storeAs('categories', $uniqueName, 'public');
        }

        $materialGroup->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Material group updated successfully',
            'data' => $materialGroup->fresh(),
        ], 200);
    }

    public function destroy(Request $request, MaterialGroup $materialGroup): JsonResponse
    {
        $this->authorizeMutation($request, 'materials.delete');

        if ($materialGroup->image && Storage::disk('public')->exists($materialGroup->image)) {
            Storage::disk('public')->delete($materialGroup->image);
        }

        $materialGroup->delete();

        return response()->json([
            'success' => true,
            'message' => 'Material group deleted successfully',
        ], 200);
    }

    private function authorizeMutation(Request $request, string $permission): void
    {
        $user = $request->user('sanctum');

        abort_unless($user, 401, 'Unauthenticated');

        if (in_array($user->role?->name, ['admin', 'manager'], true)) {
            return;
        }

        abort_unless(
            method_exists($user, 'hasPermission') && $user->hasPermission($permission),
            403,
            'Forbidden'
        );
    }
}
