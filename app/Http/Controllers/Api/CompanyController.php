<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Factory;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Database\Seeders\FactorySeeder;
use Database\Seeders\FactoryFileExtensionSeeder;
use Database\Seeders\FactoryOrderStatusSeeder;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = Company::query()->orderBy('name');
        if (!$request->user()->is_platform_admin) $query->where('is_active', true)
            ->whereHas('memberships', fn ($q) => $q->where('user_id', $request->user()->id)->where('is_active', true));
        return response()->json(['companies' => $query->get()->map(fn (Company $company) => $company->summary())]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->is_platform_admin, 403);
        $request->merge(['slug' => strtolower(trim((string) $request->input('slug')))]);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'slug' => ['required', 'alpha_dash', 'max:80', 'unique:companies,slug'],
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);
        $company = DB::transaction(function () use ($data, $request) {
            $company = Company::create(['name' => $data['name'], 'slug' => strtolower($data['slug'])]);
            if ($request->hasFile('logo')) $company->update(['logo_path' => $request->file('logo')->store("companies/{$company->id}/branding", 'private')]);
            app(CompanyContext::class)->run($company, function () {
                (new FactorySeeder)->run();
                (new FactoryFileExtensionSeeder)->run();
                (new FactoryOrderStatusSeeder)->run();
            });
            return $company;
        });
        return response()->json(['company' => $company->summary()], 201);
    }

    public function update(Request $request, Company $company)
    {
        abort_unless($request->user()->is_platform_admin, 403);
        if ($request->has('slug')) $request->merge(['slug' => strtolower(trim((string) $request->input('slug')))]);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => ['sometimes', 'required', 'alpha_dash', 'max:80', Rule::unique('companies', 'slug')->ignore($company->id)],
            'is_active' => 'sometimes|boolean', 'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);
        if (isset($data['is_active']) && !$data['is_active']) abort_if(Company::where('is_active', true)->whereKeyNot($company->id)->doesntExist(), 422, 'Keep at least one active company.');
        unset($data['logo']);
        if (isset($data['slug'])) $data['slug'] = strtolower($data['slug']);
        if ($request->hasFile('logo')) $data['logo_path'] = $request->file('logo')->store("companies/{$company->id}/branding", 'private');
        $company->update($data);
        return response()->json(['company' => $company->summary()]);
    }

    public function logo(Request $request, Company $company)
    {
        $allowed = $request->user()->is_platform_admin || ($company->is_active && $company->memberships()->where('user_id', $request->user()->id)->where('is_active', true)->exists());
        abort_unless($allowed, 404);
        abort_unless($company->logo_path && Storage::disk('private')->exists($company->logo_path), 404);
        return response()->file(Storage::disk('private')->path($company->logo_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
