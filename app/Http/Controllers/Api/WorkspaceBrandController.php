<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Support\Facades\Storage;

class WorkspaceBrandController extends Controller
{
    public function index()
    {
        return response()->json(['brands' => Company::where('is_active', true)->orderBy('id')->get()
            ->map(fn (Company $company) => [
                'id' => $company->id, 'name' => $company->name, 'slug' => $company->slug,
                'logo' => $company->logo_path ? '/api/workspace/brands/' . $company->id . '/logo?v=' . $company->updated_at?->timestamp : null,
            ])])->header('Cache-Control', 'no-store');
    }

    public function logo(Company $company)
    {
        // Only branding is public. Workshops, uploads and membership directories
        // keep their existing authenticated, company-specific endpoints.
        abort_unless($company->is_active && $company->logo_path && Storage::disk('private')->exists($company->logo_path), 404);
        return response()->file(Storage::disk('private')->path($company->logo_path), [
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
