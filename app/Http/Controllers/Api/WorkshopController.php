<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Factory, FactoryOrder};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};

class WorkshopController extends Controller
{
    public function index() { return response()->json(['workshops' => Factory::where('is_workshop', true)->withCount('orders')->orderBy('name')->get()]); }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $factory = Factory::create($data + ['value' => 'WORK_'.Str::upper(Str::random(12)), 'is_workshop' => true, 'is_reference' => false, 'is_active' => true]);
        return response()->json(['workshop' => $factory], 201);
    }

    public function update(Request $request, Factory $workshop)
    {
        abort_unless($workshop->is_workshop, 404);
        $data = $this->validated($request, $workshop);
        DB::transaction(function () use ($workshop, $data) {
            $workshop = Factory::whereKey($workshop->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('is_active', $data) && !$data['is_active']) {
                $open = FactoryOrder::where('factory_id', $workshop->id)->whereNull('completed_at')->whereNotIn('status', ['canceled', 'cancelled'])->exists();
                $assigned = DB::table('membership_assignments')->join('company_memberships', 'company_memberships.id', '=', 'membership_assignments.membership_id')
                    ->where('company_memberships.company_id', $workshop->company_id)->where('company_memberships.is_active', true)->where('membership_assignments.factory_id', $workshop->id)->exists();
                if ($open || $assigned) throw ValidationException::withMessages(['is_active' => ['Նախ ավարտեք կամ փոխանցեք բաց աշխատանքները և աշխատակիցների նշանակումները։ Պատմությունն ու ֆայլերը կպահպանվեն։']]);
            }
            $workshop->update($data);
            if (array_key_exists('is_active', $data) && !$data['is_active']) \App\Models\FileCategory::where('default_factory_id', $workshop->id)->update(['default_factory_id' => null]);
        });
        return response()->json(['workshop' => $workshop->fresh()]);
    }

    private function validated(Request $request, ?Factory $workshop = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name', $workshop?->name))]);
        return $request->validate(['name' => ['required', 'string', 'max:150', Rule::unique('factories', 'name')->where('company_id', app(\App\Support\CompanyContext::class)->id())->ignore($workshop?->id)], 'is_active' => 'sometimes|boolean']);
    }
}
