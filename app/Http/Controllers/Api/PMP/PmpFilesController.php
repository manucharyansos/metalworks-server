<?php

namespace App\Http\Controllers\Api\PMP;

use App\Http\Controllers\Controller;
use App\Models\BendFileExtension;
use App\Models\Factory;
use App\Models\LaserFileExtension;
use App\Models\Pmp;
use App\Models\PmpFiles;
use App\Models\RemoteNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PmpFilesController extends Controller
{
    public function index(): JsonResponse
    {
        $files = PmpFiles::with(['factory', 'pmp', 'remoteNumber'])->get();
        return response()->json(['files' => $files]);
    }

    public function show($id): JsonResponse
    {
        $file = PmpFiles::with(['factory', 'pmp', 'remoteNumber'])->find($id);

        return $file
            ? response()->json(['file' => $file])
            : response()->json(['error' => 'File not found'], 404);
    }

    public function upload(Request $request): JsonResponse
    {
        $storedPath = null;

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
            }

            $validated = $request->validate($rules);
            $file = $validated['file'];
            $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
            $extension = strtolower(ltrim($file->getClientOriginalExtension(), '.'));

            $allowed = array_values(array_filter(array_map(
                static fn ($item) => strtolower(ltrim(trim((string) $item), '.')),
                $this->getAllowedExtensions($factory->value)
            )));

            if (!in_array($extension, $allowed, true)) {
                return response()->json([
                    'error' => 'Ֆայլի տեսակը թույլատրված չէ։ Թույլատրելի են՝ ' . implode(', ', $allowed),
                ], 422);
            }

            $pmp = Pmp::findOrFail($validated['pmp_id']);
            $remote = RemoteNumber::findOrFail($validated['remote_number_id']);

            if ((int) $remote->pmp_id !== (int) $pmp->id) {
                return response()->json([
                    'error' => 'Ընտրված հեռակա համարը չի պատկանում այս PMP-ին։',
                ], 422);
            }

            if (PmpFiles::where('remote_number_id', $remote->id)
                ->where('factory_id', $factory->id)
                ->where('original_name', $originalName)
                ->exists()) {
                return response()->json([
                    'error' => 'Ֆայլի այս անունն արդեն օգտագործված է այս գործարանի համար',
                ], 409);
            }

            $path = "MetalWorks/PMP_{$pmp->group}.{$remote->remote_number}/{$factory->value}";
            Storage::disk('public')->makeDirectory($path);

            $uniqueName = Str::uuid()->toString() . '.' . $extension;
            $storedPath = $file->storeAs($path, $uniqueName, 'public');

            $record = PmpFiles::create([
                'pmp_id' => $pmp->id,
                'remote_number_id' => $remote->id,
                'factory_id' => $factory->id,
                'path' => $storedPath,
                'original_name' => $originalName,
                'file_type' => $extension,
                'quantity' => $validated['quantity'] ?? null,
                'material_type' => $validated['material_type'] ?? null,
                'thickness' => $validated['thickness'] ?? null,
            ]);

            return response()->json([
                'message' => 'Ֆայլը հաջողությամբ վերբեռնվեց',
                'file' => $record,
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }

            Log::error('PMP file upload failed', [
                'user_id' => $request->user()?->id,
                'pmp_id' => $request->input('pmp_id'),
                'remote_number_id' => $request->input('remote_number_id'),
                'factory_id' => $request->input('factory_id'),
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Ֆայլի վերբեռնման ընթացքում սխալ է տեղի ունեցել։',
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $file = PmpFiles::find($id);
        if (!$file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $path = $file->path;
        $file->delete();

        foreach (['private', 'public'] as $disk) {
            if ($path && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        }

        return response()->json(['message' => 'File deleted successfully']);
    }

    private function getAllowedExtensions(string $factoryType): array
    {
        return match ($factoryType) {
            'SW' => ['sldprt', 'sldasm', 'slddrw'],
            'DLD' => BendFileExtension::pluck('extension')->toArray(),
            'DXF' => LaserFileExtension::pluck('extension')->toArray(),
            'IQS' => ['iqs'],
            'INFO' => ['txt', 'csv'],
            'PDF' => ['pdf'],
            default => throw new \RuntimeException('Unsupported factory file type'),
        };
    }
}
