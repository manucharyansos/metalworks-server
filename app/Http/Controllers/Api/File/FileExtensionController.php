<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Models\FileExtension;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $data = $this->validated($request);

        $extension = FileExtension::create($data);

        return response()->json(['data' => $extension], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(FileExtension $fileExtension): JsonResponse
    {
        return response()->json($fileExtension);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(FileExtension $fileExtension): JsonResponse
    {
        return response()->json($fileExtension);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, FileExtension $fileExtension): JsonResponse
    {
        $data = $this->validated($request, $fileExtension);

        $fileExtension->update($data);

        return response()->json(['data' => $fileExtension->fresh()]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FileExtension $fileExtension): JsonResponse
    {
        $fileExtension->delete();

        return response()->json(['message' => 'Resource deleted successfully.']);
    }

    public function create()
    {

    }

    private function validated(Request $request, ?FileExtension $extension = null): array
    {
        $request->merge(['extension' => strtolower(ltrim(trim((string) $request->input('extension')), '.'))]);
        return $request->validate(['extension' => [
            'required', 'string', 'max:20', 'regex:/^(?:\*|[a-z0-9][a-z0-9._+\-]*)$/i',
            Rule::unique('file_extensions', 'extension')
                ->where('company_id', app(\App\Support\CompanyContext::class)->id())
                ->ignore($extension?->id),
        ]]);
    }
}
