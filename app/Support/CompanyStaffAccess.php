<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\Request;

final class CompanyStaffAccess
{
    public static function sync(Request $request, User $user): void
    {
        if (!$request->has('company_access')) return;
        $actor = $request->user();
        abort_unless(in_array($actor->role?->name, ['admin', 'manager'], true), 403);
        $data = $request->validate(['company_access' => 'present|array', 'company_access.*.company_id' => 'required|integer|distinct|exists:companies,id', 'company_access.*.enabled' => 'required|boolean']);
        abort_if($user->is_platform_admin, 422, 'Platform administrator access is managed separately.');
        CompanyMembership::where('user_id', $actor->id)->orderBy('id')->lockForUpdate()->get();
        $companies = CompanyManagement::companies($actor)->keyBy('id');
        abort_unless($companies->has(app(CompanyContext::class)->id()), 403, 'Company access denied.');
        foreach ($data['company_access'] as $row) {
            $company = $companies->get($row['company_id']);
            abort_unless($company, 403, 'You cannot manage access to this company.');
            $original = $request->input('company_access');
            $row = collect($original)->firstWhere('company_id', $row['company_id']);
            $membership = CompanyMembership::where('company_id', $company->id)->where('user_id', $user->id)->lockForUpdate()->first();
            $roles = app(CompanyContext::class)->run($company, fn () => MembershipAssignments::roleNames($membership));
            abort_if(!$actor->is_platform_admin && in_array('admin', $roles, true), 403, 'Administrator access is managed by the platform administrator.');
            abort_if($company->id === app(CompanyContext::class)->id() && !$row['enabled'], 409, 'Remove access through the employee form.');
            if (!$row['enabled']) {
                CompanyMembership::where('company_id', $company->id)->where('user_id', $user->id)->update(['is_active' => false]);
                continue;
            }
            abort_unless($company->is_active, 422, 'Company is inactive.');
            app(CompanyContext::class)->run($company, function () use ($row, $company, $user, $request) {
                $allowed = $request->user()->is_platform_admin ? MembershipAssignments::STAFF_ROLES : array_values(array_diff(MembershipAssignments::STAFF_ROLES, ['admin']));
                $assignments = MembershipAssignments::validate($row, $allowed);
                $membership = CompanyMembership::firstOrNew(['company_id' => $company->id, 'user_id' => $user->id]);
                $reactivating = $membership->exists && !$membership->is_active;
                $samePrimary = (int) $membership->role_id === $assignments[0]['role_id'] && (int) $membership->factory_id === (int) $assignments[0]['factory_id'];
                if (!$membership->exists) $membership->fill([...$assignments[0], 'is_active' => true])->save();
                else $membership->update(['is_active' => true]);
                // The current company's worker form owns its full assignments.
                // Older all-company forms must not collapse unchanged multi-role access.
                if (isset($row['assignments']) || !$samePrimary || $reactivating) MembershipAssignments::sync($membership, $assignments, $reactivating || (!isset($row['assignments']) && !$samePrimary));
                // Employment contact data belongs to each company separately.
                $user->worker()->firstOrCreate([], [
                    'last_name' => $request->input('last_name'),
                    'phone' => $request->input('phone', ''),
                    'second_phone' => $request->input('second_phone'),
                    'address' => $request->input('address'),
                ]);
            });
        }
        app(CompanyContext::class)->forgetMembership($user->id);
        $user->unsetRelation('role')->unsetRelation('factory')->unsetRelation('worker');
    }

    public static function rows(User $user, User $actor): array
    {
        return CompanyMembership::where('user_id', $user->id)->whereIn('company_id', CompanyManagement::companies($actor)->modelKeys())->get(['id', 'user_id', 'company_id', 'role_id', 'factory_id', 'is_active'])
            ->map(fn ($row) => ['company_id' => $row->company_id, 'role_id' => $row->role_id, 'factory_id' => $row->factory_id, 'enabled' => $row->is_active,
                'read_only' => !$actor->is_platform_admin && app(CompanyContext::class)->run(Company::findOrFail($row->company_id), fn () => in_array('admin', MembershipAssignments::roleNames($row), true)),
                'assignments' => app(CompanyContext::class)->run(Company::findOrFail($row->company_id), fn () => MembershipAssignments::rows($row))])->all();
    }
}
