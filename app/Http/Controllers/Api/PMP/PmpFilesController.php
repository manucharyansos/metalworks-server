<?php

namespace App\Http\Controllers\Api\PMP;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\FileCategory;
use App\Models\Pmp;
use App\Models\PmpFiles;
use App\Models\RemoteNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\ProjectFilePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
            return DB::transaction(function () use ($request, &$storedPath) {
                $rules = [
                    'pmp_id' => 'required|exists:pmps,id',
                    'remote_number_id' => 'required|exists:remote_numbers,id',
                    'factory_id' => 'required|exists:factories,id',
                    'file_category_id' => 'nullable|integer|exists:file_categories,id',
                    'file' => 'required|file|max:102400',
                ];

                $request->validate(['factory_id' => 'required_without:file_category_id|nullable|integer|exists:factories,id', 'file_category_id' => 'nullable|integer|exists:file_categories,id', 'file' => 'required|file|max:102400']);
                $category = null;
                if (Schema::hasTable('file_categories')) {
                    $category = FileCategory::query()
                        ->when($request->filled('file_category_id'),
                            fn ($query) => $query->whereKey($request->integer('file_category_id')),
                            fn ($query) => $query->where('legacy_factory_id', $request->integer('factory_id')))
                        ->lockForUpdate()->first();
                    if (!$category) throw ValidationException::withMessages(['file_category_id' => ['Ֆայլերի բաժինը չի գտնվել։ Թարմացրեք ցանկը և ընտրեք գործող բաժին։']]);
                }
                abort_unless(!$category || $category->is_active, 422, 'Ֆայլերի բաժինը արխիվացված է։');
                if ($category && $request->filled('factory_id') && (int) $request->input('factory_id') !== (int) $category->legacy_factory_id) throw ValidationException::withMessages(['file_category_id' => ['Ֆայլերի բաժինը չի համապատասխանում ընտրված բաժնին։']]);
                if ($category) $request->merge(['factory_id' => $category->legacy_factory_id, 'file_category_id' => $category->id]);
                $factory = Factory::findOrFail($request->input('factory_id'));

                if ($factory->value === 'DXF' && strtolower($request->file('file')?->getClientOriginalExtension() ?? '') === 'dxf') {
                    $rules['quantity'] = 'required|integer|min:1';
                    $rules['material_type'] = 'required|string|max:255';
                    $rules['thickness'] = 'required|numeric|min:0';
                }

                $validated = $request->validate($rules);
                $file = $validated['file'];
                $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $extension = strtolower(ltrim($file->getClientOriginalExtension(), '.'));

                ProjectFilePolicy::assertAllowed($factory, $originalName);

                $pmp = Pmp::findOrFail($validated['pmp_id']);
                $remote = RemoteNumber::findOrFail($validated['remote_number_id']);

                if ((int) $remote->pmp_id !== (int) $pmp->id) {
                    return response()->json([
                        'error' => 'Ընտրված հեռակա համարը չի պատկանում այս նախագծին։',
                    ], 422);
                }

                if (PmpFiles::where('remote_number_id', $remote->id)
                    ->where('factory_id', $factory->id)
                    ->where('original_name', $originalName)
                    ->exists()) {
                    return response()->json([
                        'error' => 'Այս անունով ֆայլ արդեն կա ընտրված բաժնում։ Փոխեք ֆայլի անունը կամ ավելացրեք նոր տարբերակ։',
                    ], 409);
                }

                $path = "companies/" . app(\App\Support\CompanyContext::class)->id() . "/PMP_{$pmp->group}.{$remote->remote_number}/{$factory->value}";
                Storage::disk('private')->makeDirectory($path);

                $uniqueName = Str::uuid()->toString() . ($extension !== '' ? ".{$extension}" : '');
                $storedPath = $file->storeAs($path, $uniqueName, 'private');

                $record = PmpFiles::create([
                    'pmp_id' => $pmp->id,
                    'remote_number_id' => $remote->id,
                    'factory_id' => $factory->id,
                    ...($category ? ['file_category_id' => $category->id] : []),
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
            });
        } catch (\Throwable $e) {
            if ($storedPath) {
                Storage::disk('private')->delete($storedPath);
            }
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof ValidationException) throw $e;

            Log::error('Project file upload failed', [
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
        if (! $file) {
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

}
