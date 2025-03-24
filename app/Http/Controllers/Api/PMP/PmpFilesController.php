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
//    public function upload(Request $request): JsonResponse
//    {
//        try {
//            $validatedData = $request->validate([
//                'pmp_id' => 'required|exists:pmps,id',
//                'remote_number_ids' => 'required|array',
//                'remote_number_ids.*' => 'required|exists:remote_numbers,id',
//                'factory_ids' => 'required|array',
//                'factory_ids.*' => 'required|exists:factories,id',
//                'files' => 'required|array',
//                'files.*' => 'required|file|max:10240',
//                'quantities' => 'required|array',
//                'quantities.*' => 'required|integer|min:1',
//                'material_types' => 'required|array',
//                'material_types.*' => 'required|string|max:255',
//                'thicknesses' => 'required|array',
//                'thicknesses.*' => 'required|numeric|min:0',
//            ]);
//
//
//            $pmp = Pmp::findOrFail($validatedData['pmp_id']);
//
//            foreach ($validatedData['files'] as $index => $file) {
//                $remoteNumberId = $validatedData['remote_number_ids'][$index];
//                $factoryId = $validatedData['factory_ids'][$index];
//
//                $remoteNumber = RemoteNumber::findOrFail($remoteNumberId);
//                $factory = Factory::findOrFail($factoryId);
//
//                $baseDirectoryPath = "MetalWorks/PMP_{$pmp->group}.{$remoteNumber->remote_number}/{$factory->value}";
//
//                Storage::disk('public')->makeDirectory($baseDirectoryPath);
//
//                $originalName = $file->getClientOriginalName();
//                $fileName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
//
//                $path = $file->storeAs($baseDirectoryPath, $fileName, 'public');
//
//                PmpFiles::create([
//                    'pmp_id' => $pmp->id,
//                    'remote_number_id' => $remoteNumber->id,
//                    'factory_id' => $factory->id,
//                    'path' => $path,
//                    'original_name' => $originalName,
//                    'quantity' => $validatedData['quantities'][$index], // Քանակ
//                    'material_type' => $validatedData['material_types'][$index], // Նյութի տեսակ
//                    'thickness' => $validatedData['thicknesses'][$index], // Հաստություն
//                ]);
//            }
//
//
//            return response()->json(['message' => 'Files uploaded successfully'], 200);
//        } catch (\Exception $e) {
//            return response()->json(['error' => $e->getMessage()], 500);
//        }
//    }

    public function upload(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'pmp_id' => 'required|exists:pmps,id',
                'remote_number_id' => 'required|exists:remote_numbers,id',
                'factory_id' => 'required|exists:factories,id',
                'file' => 'required|file|max:10240',
                'quantity' => 'required|integer|min:1',
                'material_type' => 'required|string|max:255',
                'thickness' => 'required|numeric|min:0',
            ]);

            $pmp = Pmp::findOrFail($validatedData['pmp_id']);

            $remoteNumber = RemoteNumber::findOrFail($validatedData['remote_number_id']);
            $factory = Factory::findOrFail($validatedData['factory_id']);

            $baseDirectoryPath = "MetalWorks/PMP_{$pmp->group}.{$remoteNumber->remote_number}/{$factory->value}";
            Storage::disk('public')->makeDirectory($baseDirectoryPath);

            $originalName = $validatedData['file']->getClientOriginalName();
            $fileName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $validatedData['file']->getClientOriginalExtension();
            $path = $validatedData['file']->storeAs($baseDirectoryPath, $fileName, 'public');

            PmpFiles::create([
                'pmp_id' => $pmp->id,
                'remote_number_id' => $remoteNumber->id,
                'factory_id' => $factory->id,
                'path' => $path,
                'original_name' => $originalName,
                'quantity' => $validatedData['quantity'],
                'material_type' => $validatedData['material_type'],
                'thickness' => $validatedData['thickness'],
            ]);

            return response()->json(['message' => 'File uploaded successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a DXF file.
     *
     * @param  \App\Models\PmpFiles  $pmpFiles
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id): \Illuminate\Http\JsonResponse
    {
        $pmpFile = PmpFiles::find($id);
        if (!$pmpFile) {
            return response()->json([
                'error' => 'File not found!',
            ], 404);
        }
        $filePath = public_path("storage/" . $pmpFile->path);
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        $pmpFile->delete();
        return response()->json([
            'message' => 'File deleted successfully',
        ], 200);
    }

}
