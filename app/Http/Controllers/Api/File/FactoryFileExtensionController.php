<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\FactoryFileExtension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FactoryFileExtensionController extends Controller
{
    public function index(): JsonResponse
    {
        $factories = Factory::query()
            ->with(['fileExtensions' => fn ($query) => $query->orderBy('extension')])
            ->orderBy('name')
            ->get(['id', 'name', 'value'])
            ->map(fn (Factory $factory) => [
                'id' => $factory->id,
                'name' => $factory->name,
                'value' => $factory->value,
                'extensions' => $factory->fileExtensions->values(),
            ]);

        return response()->json(['data' => $factories]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeExtension($request);
        $factoryId = (int) $request->input('factory_id');

        $validated = $request->validate([
            'factory_id' => ['required', 'integer', 'exists:factories,id'],
            'extension' => [
                'required',
                'string',
                'max:20',
                'regex:/^[a-z0-9][a-z0-9._+\-]*$/i',
                Rule::unique('factory_file_extensions', 'extension')
                    ->where(fn ($query) => $query->where('factory_id', $factoryId)),
            ],
        ]);

        $extension = FactoryFileExtension::create($validated);

        return response()->json(['data' => $extension], 201);
    }

    public function update(Request $request, FactoryFileExtension $factoryFileExtension): JsonResponse
    {
        $this->normalizeExtension($request);

        $validated = $request->validate([
            'extension' => [
                'required',
                'string',
                'max:20',
                'regex:/^[a-z0-9][a-z0-9._+\-]*$/i',
                Rule::unique('factory_file_extensions', 'extension')
                    ->where(fn ($query) => $query->where('factory_id', $factoryFileExtension->factory_id))
                    ->ignore($factoryFileExtension->id),
            ],
        ]);

        $factoryFileExtension->update($validated);

        return response()->json(['data' => $factoryFileExtension->fresh()]);
    }

    public function destroy(FactoryFileExtension $factoryFileExtension): JsonResponse
    {
        $factoryFileExtension->delete();

        return response()->json(['message' => 'Resource deleted successfully.']);
    }

    private function normalizeExtension(Request $request): void
    {
        if (!$request->has('extension')) {
            return;
        }

        $request->merge([
            'extension' => strtolower(ltrim(trim((string) $request->input('extension')), '.')),
        ]);
    }
}
