<?php

namespace App\Http\Controllers\Api\PMP;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\Pmp;
use App\Models\PmpFiles;
use App\Models\RemoteNumber;
use App\Models\BendFileExtension;
use App\Models\LaserFileExtension;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;

class PmpFilesController extends Controller
{
    /**
     * Display all files (optional filtering)
     */
    public function index(): JsonResponse
    {
        $files = PmpFiles::with(['factory', 'pmp', 'remoteNumber'])->get();
        return response()->json(['files' => $files]);
    }

    /**
     * Show a specific file
     */
    public function show($id): JsonResponse
    {
        $file = PmpFiles::with(['factory', 'pmp', 'remoteNumber'])->find($id);

        return $file
            ? response()->json(['file' => $file])
            : response()->json(['error' => 'File not found'], 404);
    }

    /**
     * Upload new PMP file
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            $rules = [
                'pmp_id'          => 'required|exists:pmps,id',
                'remote_number_id'=> 'required|exists:remote_numbers,id',
                'factory_id'      => 'required|exists:factories,id',
                'file'            => 'required|file|max:10240',
            ];

            $factory = Factory::findOrFail($request->input('factory_id'));

            if ($factory->value === 'DXF') {
                $rules['quantity']      = 'required|integer|min:1';
                $rules['material_type'] = 'required|string|max:255';
                $rules['thickness']     = 'required|numeric|min:0';
            }

            $validated = $request->validate($rules);
            $file = $validated['file'];
            $originalName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension());

            $allowed = $this->getAllowedExtensions($factory->value);
            if (!in_array($extension, $allowed)) {
                return response()->json(['error' => "Ֆայլի տեսակը թույլատրված չէ։ Թույլատրելի են՝ " . implode(', ', $allowed)], 422);
            }

            $pmp = Pmp::findOrFail($validated['pmp_id']);
            $remote = RemoteNumber::findOrFail($validated['remote_number_id']);

            if (PmpFiles::where('pmp_id', $pmp->id)
                ->where('factory_id', $factory->id)
                ->where('original_name', $originalName)
                ->exists()) {
                return response()->json(['error' => 'Այս անունով ֆայլ արդեն գոյություն ունի'], 409);
            }

            $path = "MetalWorks/PMP_{$pmp->group}.{$remote->remote_number}/{$factory->value}";
            Storage::disk('public')->makeDirectory($path);

            $uniqueName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . $extension;
            $storedPath = $file->storeAs($path, $uniqueName, 'public');

            $record = PmpFiles::create([
                'pmp_id'          => $pmp->id,
                'remote_number_id'=> $remote->id,
                'factory_id'      => $factory->id,
                'path'            => $storedPath,
                'original_name'   => $originalName,
                'file_type'       => $extension,
                'quantity'        => $validated['quantity'] ?? null,
                'material_type'   => $validated['material_type'] ?? null,
                'thickness'       => $validated['thickness'] ?? null,
            ]);

            return response()->json(['message' => 'Ֆայլը հաջողությամբ վերբեռնվեց', 'file' => $record], 201);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a file physically + from DB
     */
    public function destroy($id): JsonResponse
    {
        $file = PmpFiles::find($id);
        if (!$file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        if (Storage::disk('public')->exists($file->path)) {
            Storage::disk('public')->delete($file->path);
        }

        $file->delete();
        return response()->json(['message' => 'File deleted successfully']);
    }

    /**
     * Allowed extensions by factory type
     */
    private function getAllowedExtensions(string $factoryType): array
    {
        return match ($factoryType) {
            'SW'   => ['sldprt', 'sldasm', 'slddrw'],
            'DLD'  => BendFileExtension::pluck('extension')->toArray(),
            'DXF'  => LaserFileExtension::pluck('extension')->toArray(),
            'IQS'  => ['iqs'],
            'INFO' => ['txt', 'csv'],
            'PDF'  => ['pdf'],
            default => throw new \Exception("Ֆայլի տեսակը չի սահմանված այս գործարանի համար"),
        };
    }
}
