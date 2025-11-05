<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Models\FileExtension;
use App\Models\LaserFileExtension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaserFileExtensionController extends Controller
{
    public function index(): JsonResponse
    {
        $extensions = LaserFileExtension::all();
        return response()->json($extensions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'extension' => 'required|string|unique:laser_file_extensions|max:10',
        ]);

        $extension = LaserFileExtension::create($data);

        return response()->json(['data' => $extension], 201);
    }

    public function update(Request $request, LaserFileExtension $laserFileExtension): JsonResponse
    {
        $data = $request->validate([
            'extension' => 'required|string|unique:laser_file_extensions,extension,' . $laserFileExtension->id . '|max:10',
        ]);

        $laserFileExtension->update($data);

        return response()->json(['data' => $laserFileExtension]);
    }

    public function destroy(LaserFileExtension $laserFileExtension): JsonResponse
    {
        $laserFileExtension->delete();

        return response()->json(['message' => 'Resource deleted successfully.']);
    }

    public function create()
    {

    }

    public function show(FileExtension $fileExtension)
    {

    }

    public function edit(FileExtension $fileExtension)
    {

    }
}
