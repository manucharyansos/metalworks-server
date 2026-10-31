<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Factory, WorkshopFunction};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Managers keep each workshop's list of extra functions; archived ones stay in history. */
class WorkshopFunctionController extends Controller
{
    public function store(Request $request, Factory $workshop)
    {
        abort_unless($workshop->is_workshop && \App\Support\WorkshopFunctions::enabled(), 404);
        $data = $this->validated($request, $workshop);
        $function = WorkshopFunction::create($data + ['factory_id' => $workshop->id, 'is_active' => true,
            'position' => (int) WorkshopFunction::where('factory_id', $workshop->id)->max('position') + 1]);
        return response()->json(['function' => $function->fresh()], 201);
    }

    public function update(Request $request, Factory $workshop, WorkshopFunction $workshopFunction)
    {
        abort_unless($workshop->is_workshop && (int) $workshopFunction->factory_id === (int) $workshop->id, 404);
        $workshopFunction->update($this->validated($request, $workshop, $workshopFunction));
        return response()->json(['function' => $workshopFunction->fresh()]);
    }

    private function validated(Request $request, Factory $workshop, ?WorkshopFunction $function = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name', $function?->name))]);
        return $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('workshop_functions', 'name')->where('factory_id', $workshop->id)->ignore($function?->id)],
            'is_active' => 'sometimes|boolean',
        ], ['name.unique' => 'Այս արտադրամասում նույն անունով ֆունկցիա արդեն կա։']);
    }
}
