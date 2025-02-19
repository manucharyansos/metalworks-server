<?php

namespace App\Http\Controllers\Api\PMP;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\Pmp;
use App\Models\PmpFiles;
use App\Models\RemoteNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\JsonResponse;

class PmpFilesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(PmpFiles $pmpFiles)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PmpFiles $pmpFiles)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'pmp_id' => 'required|exists:pmps,id',
                'remote_number_ids' => 'required|array',
                'remote_number_ids.*' => 'required|exists:remote_numbers,id',
                'factory_ids' => 'required|array',
                'factory_ids.*' => 'required|exists:factories,id',
                'files' => 'required|array',
                'files.*' => 'required|file|max:10240',
            ]);

            $pmp = Pmp::findOrFail($validatedData['pmp_id']);

            foreach ($validatedData['files'] as $index => $file) {
                $remoteNumberId = $validatedData['remote_number_ids'][$index]; // Ընտրված remote_number
                $factoryId = $validatedData['factory_ids'][$index]; // Ընտրված գործարան

                $remoteNumber = RemoteNumber::findOrFail($remoteNumberId);
                $factory = Factory::findOrFail($factoryId);

                $baseDirectoryPath = "MetalWorks/PMP_{$pmp->group}.{$remoteNumber->remote_number}/{$factory->value}";

                Storage::disk('public')->makeDirectory($baseDirectoryPath);

                $originalName = $file->getClientOriginalName();
                $fileName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $path = $file->storeAs($baseDirectoryPath, $fileName, 'public');

                PmpFiles::create([
                    'pmp_id' => $pmp->id,
                    'remote_number_id' => $remoteNumber->id,
                    'factory_id' => $factory->id,
                    'path' => $path,
                    'original_name' => $originalName,
                ]);
            }

            return response()->json(['message' => 'Files uploaded successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PmpFiles $pmpFiles)
    {
        //
    }
}
