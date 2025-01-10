<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Models\BendFileExtension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BendFileExtensionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $extensions = BendFileExtension::all();
        return response()->json($extensions);
    }

    /**
     * Store a newly created resource in storage.
     */

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'extension' => 'required|string|unique:bend_file_extensions|max:10',
        ]);

        $extension = BendFileExtension::create($data);

        return response()->json(['data' => $extension], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(BendFileExtension $bendFileExtension): JsonResponse
    {
        return response()->json($bendFileExtension);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(BendFileExtension $bendFileExtension): JsonResponse
    {
        return response()->json($bendFileExtension);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BendFileExtension $bendFileExtension): JsonResponse
    {
        $data = $request->validate([
            'extension' => 'required|string|unique:bend_file_extensions,extension,' . $bendFileExtension->id . '|max:10',
        ]);

        $bendFileExtension->update($data);

        return response()->json(['data' => $bendFileExtension]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BendFileExtension $bendFileExtension): JsonResponse
    {
        $bendFileExtension->delete();

        return response()->json(['message' => 'Resource deleted successfully.']);
    }
}
