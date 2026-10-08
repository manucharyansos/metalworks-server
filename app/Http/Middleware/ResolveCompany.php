<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ResolveCompany
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(CompanyContext::class);
        $context->set(null);
        try {
            // These routes authenticate or manage the shared personal identity.
            if ($request->is('api/login', 'api/register', 'api/logout', 'api/password/*', 'api/forgot-password', 'api/reset-password')) {
                return $next($request);
            }
            $user = $request->user();
            if (!$user) return $next($request); // auth:sanctum rejects protected routes.
            abort_unless(Schema::hasTable('company_memberships'), 503, 'Run the company migrations before using the workspace.');

            // Company directory and ownership administration have their own
            // membership/platform checks and must not inherit a selected tenant.
            if ($request->is('api/companies', 'api/companies/*')) return $next($request);

            $selected = $request->header('X-Company-ID', $request->query('company_id'));
            $query = Company::query()->where('is_active', true);
            if (!$user->is_platform_admin) {
                $query->whereHas('memberships', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true));
            }
            if ($selected !== null && $selected !== '') {
                abort_unless(ctype_digit((string) $selected), 403, 'Company access denied.');
                $company = $query->whereKey((int) $selected)->first();
                abort_unless($company, 403, 'Company access denied.');
            } else {
                $companies = $query->orderBy('id')->get();
                if ($companies->isEmpty()) {
                    abort_unless($request->is('api/user', 'api/profile', 'api/profile/identity', 'api/profile/password', 'api/profile/email/*'), 403, 'No company access.');
                    return $next($request);
                }
                abort_if($companies->count() > 1 && !$request->is('api/user'), 409, 'Choose a company.');
                $company = $companies->first();
            }
            if ($user->is_platform_admin && !CompanyMembership::where('company_id', $company->id)->where('user_id', $user->id)->exists()) {
                CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $user->getRawOriginal('role_id'), 'is_active' => true]);
            }
            $context->set($company);
            if (!$request->isMethod('GET') && $request->is('api/roles', 'api/roles/*', 'api/permissions', 'api/permissions/*')) abort_unless($user->is_platform_admin, 403);
            $user->unsetRelation('role')->unsetRelation('factory')->unsetRelation('worker')->unsetRelation('client');
            $response = $next($request);
            $response->headers->set('X-Company-ID', (string) $company->id);
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;
        } finally { $context->set(null); }
    }
}
