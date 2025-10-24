<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Models\FileExtension;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class FileExtensionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $extensions = FileExtension::all();
        return response()->json($extensions);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'extension' => 'required|string|unique:file_extensions|max:10',
        ]);

        $extension = FileExtension::create($data);

        return response()->json(['data' => $extension], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(FileExtension $FileExtension): JsonResponse
    {
        return response()->json($FileExtension);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(FileExtension $FileExtension): JsonResponse
    {
        return response()->json($FileExtension);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, FileExtension $FileExtension): JsonResponse
    {
        $data = $request->validate([
            'extension' => 'required|string|unique:file_extensions,extension,' . $FileExtension->id . '|max:10',
        ]);

        $FileExtension->update($data);

        return response()->json(['data' => $FileExtension]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FileExtension $FileExtension): JsonResponse
    {
        $FileExtension->delete();

        return response()->json(['message' => 'Resource deleted successfully.']);
    }
}
