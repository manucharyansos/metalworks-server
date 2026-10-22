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
    public function uploadOptions(): JsonResponse
    {
        return response()->json([
            'materials' => \App\Models\Material::select('id', 'description', 'width', 'length', 'height', 'thickness')->orderBy('description')->get(),
            'workshops' => Factory::activeWorkshops()->orderBy('id')->get(['id', 'name', 'value', 'is_workshop', 'is_reference', 'is_active']),
        ]);
    }

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
        $storedPaths = [];
        try {
            return DB::transaction(function () use ($request, &$storedPaths) {
                foreach (['production_factory_ids', 'file_plans'] as $field) {
                    if (is_string($request->input($field))) {
                        try { $request->merge([$field => json_decode($request->input($field), true, 512, JSON_THROW_ON_ERROR)]); }
                        catch (\JsonException $e) { throw ValidationException::withMessages([$field => ['Ֆայլի արտադրական ուղու տվյալները սխալ են։']]); }
                    }
                }
                if ($request->hasFile('file') && $request->hasFile('files')) throw ValidationException::withMessages(['files' => ['Ընտրեք մեկ ֆայլ կամ ֆայլերի խումբ։']]);
                $validated = $request->validate([
                    'pmp_id' => 'required|exists:pmps,id', 'remote_number_id' => 'required|exists:remote_numbers,id',
                    'factory_id' => 'required_without:file_category_id|nullable|integer|exists:factories,id',
                    'file_category_id' => 'nullable|integer|exists:file_categories,id',
                    'file' => 'required_without:files|file|max:102400',
                    'files' => 'required_without:file|array|min:1|max:50',
                    'files.*' => 'required|file|max:102400',
                    'quantity' => 'nullable|integer|min:1', 'material_type' => 'nullable|string|max:255',
                    'thickness' => 'nullable|numeric|min:0', 'dimensions' => 'nullable|string|max:255',
                    'file_plans' => 'sometimes|array|max:50',
                    'expected_file_count' => 'sometimes|integer|min:1|max:50',
                ] + \App\Support\ProjectFilePlan::rules() + \App\Support\ProjectFilePlan::rules('file_plans.*.'));
                $batch = $request->hasFile('files');
                $uploads = $batch ? array_values($request->file('files')) : [$request->file('file')];
                if (isset($validated['expected_file_count']) && count($uploads) !== (int) $validated['expected_file_count']) {
                    throw ValidationException::withMessages(['files' => ['Սերվերը չի ընդունել բոլոր ընտրված ֆայլերը։ Ընտրեք ավելի փոքր խումբ կամ ստուգեք PHP-ի max_file_uploads սահմանափակումը։']]);
                }
                if (array_sum(array_map(fn ($file) => $file->getSize(), $uploads)) > 100 * 1024 * 1024) {
                    throw ValidationException::withMessages(['files' => ['Ընտրված ֆայլերի ընդհանուր չափը պետք է լինի առավելագույնը 100 ՄԲ։']]);
                }
                if (array_diff(array_keys($validated['file_plans'] ?? []), array_keys($uploads))) {
                    throw ValidationException::withMessages(['file_plans' => ['Ֆայլի արտադրական ուղին չի համապատասխանում ընտրված ֆայլին։']]);
                }
                $category = null;
                if (Schema::hasTable('file_categories')) {
                    $category = FileCategory::query()->when($request->filled('file_category_id'),
                        fn ($query) => $query->whereKey($request->integer('file_category_id')),
                        fn ($query) => $query->where('legacy_factory_id', $request->integer('factory_id')))->lockForUpdate()->first();
                    if (!$category) throw ValidationException::withMessages(['file_category_id' => ['Ֆայլերի բաժինը չի գտնվել։ Թարմացրեք ցանկը և ընտրեք գործող բաժին։']]);
                }
                abort_unless(!$category || $category->is_active, 422, 'Ֆայլերի բաժինը արխիվացված է։');
                if ($category && $request->filled('factory_id') && (int) $request->input('factory_id') !== (int) $category->legacy_factory_id) {
                    throw ValidationException::withMessages(['file_category_id' => ['Ֆայլերի բաժինը չի համապատասխանում ընտրված բաժնին։']]);
                }
                $factory = Factory::whereKey($category?->legacy_factory_id ?? $request->integer('factory_id'))->lockForUpdate()->firstOrFail();
                $reference = $category?->is_reference ?? $factory->is_reference;
                $pmp = Pmp::findOrFail($validated['pmp_id']);
                $remote = RemoteNumber::findOrFail($validated['remote_number_id']);
                if ((int) $remote->pmp_id !== (int) $pmp->id) return response()->json(['error' => 'Ընտրված հեռակա համարը չի պատկանում այս նախագծին։'], 422);
                $names = []; $plans = [];
                foreach ($uploads as $index => $file) {
                    $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                    ProjectFilePolicy::assertAllowed($factory, $name);
                    if ($batch && ProjectFilePolicy::needsAttributes($name)) {
                        throw ValidationException::withMessages(['files.'.$index => ['DXF և IC ֆայլերն ավելացրեք առանձին՝ լրացնելով անհատական դաշտերը։']]);
                    }
                    if (!$batch && !$reference && ProjectFilePolicy::needsAttributes($name)) {
                        $request->validate(['quantity' => 'required|integer|min:1', 'material_type' => 'required|string|max:255', 'thickness' => 'required|numeric|min:0']);
                    }
                    if (in_array($name, $names, true) || PmpFiles::where('remote_number_id', $remote->id)->where('factory_id', $factory->id)->where('original_name', $name)->exists()) {
                        return response()->json(['error' => 'Այս անունով ֆայլ արդեն կա ընտրված բաժնում կամ ընտրության մեջ։ Փոխեք ֆայլի անունը կամ ավելացրեք նոր տարբերակ։'], 409);
                    }
                    $names[] = $name;
                    $plans[$index] = $batch ? ($validated['file_plans'][$index] ?? []) : $validated;
                    \App\Support\ProjectFilePlan::validate($plans[$index], $reference, $batch ? 'file_plans.'.$index.'.production_factory_ids' : 'production_factory_ids');
                }
                $path = 'companies/'.app(\App\Support\CompanyContext::class)->id()."/PMP_{$pmp->group}.{$remote->remote_number}/{$factory->value}";
                $records = [];
                foreach ($uploads as $index => $file) {
                    $extension = strtolower($file->getClientOriginalExtension());
                    $storedPath = $file->storeAs($path, Str::uuid().($extension !== '' ? '.'.$extension : ''), 'private');
                    if (!$storedPath) throw new \RuntimeException('Project file storage failed');
                    $storedPaths[] = $storedPath;
                    $attributes = !$batch && !$reference && ProjectFilePolicy::needsAttributes($names[$index]);
                    $plan = $plans[$index];
                    $records[] = PmpFiles::create([
                        'pmp_id' => $pmp->id, 'remote_number_id' => $remote->id, 'factory_id' => $factory->id,
                        ...($category ? ['file_category_id' => $category->id] : []),
                        'path' => $storedPath, 'original_name' => $names[$index],
                        'quantity' => $attributes ? $validated['quantity'] : null,
                        'material_type' => $attributes ? $validated['material_type'] : null,
                        'thickness' => $attributes ? $validated['thickness'] : null,
                        'dimensions' => $attributes ? ($validated['dimensions'] ?? null) : null,
                        'production_factory_ids' => array_key_exists('production_factory_ids', $plan) && $plan['production_factory_ids'] !== null
                            ? array_map('intval', $plan['production_factory_ids']) : null,
                        'inspection_required' => (bool) ($plan['inspection_required'] ?? false),
                    ]);
                }
                return response()->json(['message' => 'Ֆայլերը հաջողությամբ վերբեռնվեցին'] + ($batch ? ['files' => $records] : ['file' => $records[0]]), 201);
            });
        } catch (\Throwable $e) {
            foreach ($storedPaths as $storedPath) Storage::disk('private')->delete($storedPath);
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof ValidationException) throw $e;
            Log::error('Project file upload failed', ['user_id' => $request->user()?->id, 'pmp_id' => $request->input('pmp_id'), 'exception' => $e]);
            return response()->json(['error' => 'Ֆայլի վերբեռնման ընթացքում սխալ է տեղի ունեցել։'], 500);
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
