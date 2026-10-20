<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Factory, FileCategory, FactoryFileExtension, PmpFiles};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};

class FileCategoryController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['categories' => FileCategory::with('extensions')->when(!$request->boolean('include_archived'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $category = DB::transaction(function () use ($data) {
            $bridge = Factory::create(['name' => $data['name'], 'value' => 'FILES_'.Str::upper(Str::random(12)), 'is_workshop' => false, 'is_reference' => $data['is_reference'], 'is_active' => true]);
            $category = FileCategory::create(collect($data)->except('extensions')->all() + ['code' => $bridge->value, 'legacy_factory_id' => $bridge->id]);
            $this->extensions($category, $data['extensions']);
            return $category;
        });
        return response()->json(['category' => $category->load('extensions')], 201);
    }

    public function update(Request $request, FileCategory $fileCategory)
    {
        $data = $this->validated($request, $fileCategory);
        DB::transaction(function () use ($fileCategory, $data) {
            $fileCategory = FileCategory::whereKey($fileCategory->id)->lockForUpdate()->firstOrFail();
            $fileCategory->update(collect($data)->except('extensions')->all());
            $this->extensions($fileCategory, $data['extensions']);
        });
        return response()->json(['category' => $fileCategory->fresh()->load('extensions')]);
    }

    public function destroy(FileCategory $fileCategory)
    {
        DB::transaction(function () use ($fileCategory) {
            $category = FileCategory::whereKey($fileCategory->id)->lockForUpdate()->firstOrFail();
            // A section can share its legacy factory ID with a real workshop.
            // Remove only the section and its formats, never production history.
            $hasFiles = PmpFiles::where(function ($query) use ($category) {
                $query->where('file_category_id', $category->id)
                    ->orWhere('factory_id', $category->legacy_factory_id);
            })->exists();
            if ($hasFiles) {
                throw ValidationException::withMessages(['category' => ['Բաժինը պարունակում է ֆայլեր։ Նախ հեռացրեք կամ տեղափոխեք դրանք, ապա ջնջեք բաժինը։']]);
            }
            $category->extensions()->delete();
            $category->delete();
        });

        return response()->json(['message' => 'Ֆայլերի բաժինը ջնջված է։']);
    }

    private function validated(Request $request, ?FileCategory $category = null): array
    {
        $request->validate(['extensions' => 'present|array|max:100', 'extensions.*' => 'string']);
        $request->merge(['name' => trim((string) $request->input('name')), 'extensions' => array_values(array_unique(array_map(fn ($value) => strtolower(ltrim(trim((string) $value), '.')), $request->input('extensions', []))))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:150', Rule::unique('file_categories', 'name')->where('company_id', app(\App\Support\CompanyContext::class)->id())->ignore($category?->id)],
            'is_reference' => 'required|boolean', 'is_active' => 'sometimes|boolean', 'default_factory_id' => 'nullable|integer|exists:factories,id',
            'extensions' => 'present|array|max:100', 'extensions.*' => ['required', 'string', 'max:20', 'regex:/^(?:\*|[a-z0-9][a-z0-9._+\-]*)$/i']]);
        if (!empty($data['default_factory_id']) && !Factory::activeWorkshops()->whereKey($data['default_factory_id'])->exists()) throw ValidationException::withMessages(['default_factory_id' => ['Ընտրեք այս ընկերության գործող արտադրամասը։']]);
        if ($data['is_reference']) $data['default_factory_id'] = null;
        return $data;
    }

    private function extensions(FileCategory $category, array $extensions): void
    {
        $category->extensions()->whereNotIn('extension', $extensions)->delete();
        foreach ($extensions as $extension) FactoryFileExtension::firstOrCreate(['factory_id' => $category->legacy_factory_id, 'extension' => $extension]);
    }
}
