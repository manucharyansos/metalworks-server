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
use App\Models\BendFileExtension;
use App\Models\LaserFileExtension;

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
        $rules = [
            'pmp_id' => 'required|exists:pmps,id',
            'remote_number_id' => 'required|exists:remote_numbers,id',
            'factory_id' => 'required|exists:factories,id',
            'file' => 'required|file|max:10240',
        ];

        $factory = Factory::findOrFail($request->input('factory_id'));
        if ($factory->value === 'DXF') {
            $rules['quantity'] = 'required|integer|min:1';
            $rules['material_type'] = 'required|string|max:255';
            $rules['thickness'] = 'required|numeric|min:0';
        } else {
            $rules['quantity'] = 'nullable|integer|min:1';
            $rules['material_type'] = 'nullable|string|max:255';
            $rules['thickness'] = 'nullable|numeric|min:0';
        }

        $validatedData = $request->validate($rules);

        $pmp = Pmp::findOrFail($validatedData['pmp_id']);
        $remoteNumber = RemoteNumber::findOrFail($validatedData['remote_number_id']);

        $file = $validatedData['file'];
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();

        $allowedExtensions = $this->getAllowedExtensions($factory->value);
        if (!in_array(strtolower($extension), $allowedExtensions)) {
            throw new \Exception("Այս գործարանը ընդունում է միայն: " . implode(', ', $allowedExtensions));
        }

        if (PmpFiles::where('pmp_id', $pmp->id)
            ->where('factory_id', $factory->id)
            ->where('original_name', $originalName)
            ->exists()) {
            throw new \Exception("Այս անունով ֆայլ արդեն գոյություն ունի այս գործարանում");
        }

        $baseDirectoryPath = "MetalWorks/PMP_{$pmp->group}.{$remoteNumber->remote_number}/{$factory->value}";
        Storage::disk('public')->makeDirectory($baseDirectoryPath);

        $uniqueName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . $extension;
        $path = $file->storeAs($baseDirectoryPath, $uniqueName, 'public');

        PmpFiles::create([
            'pmp_id' => $pmp->id,
            'remote_number_id' => $remoteNumber->id,
            'factory_id' => $factory->id,
            'path' => $path,
            'original_name' => $originalName,
            'file_type' => $extension,
            'quantity' => $validatedData['quantity'] ?? null,
            'material_type' => $validatedData['material_type'] ?? null,
            'thickness' => $validatedData['thickness'] ?? null,
        ]);

        return response()->json(['message' => 'Ֆայլը հաջողությամբ վերբեռնվեց'], 200);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

private function getAllowedExtensions(string $factoryType): array
{
    switch ($factoryType) {
        case 'SW': return ['sldprt', 'sldasm', 'slddrw'];
        case 'DLD': return BendFileExtension::pluck('extension')->toArray();
        case 'DXF': return LaserFileExtension::pluck('extension')->toArray();
        case 'IQS': return ['iqs'];
        case 'INFO': return ['txt', 'csv'];
        case 'PDF': return ['pdf'];
        default: throw new \Exception("Ֆայլի տեսակի սահմանափակումներ չեն սահմանված այս գործարանի համար");
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
