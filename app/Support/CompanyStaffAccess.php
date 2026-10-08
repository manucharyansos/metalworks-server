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
        abort_unless($request->user()->is_platform_admin, 403);
        $data = $request->validate(['company_access' => 'present|array', 'company_access.*.company_id' => 'required|integer|distinct|exists:companies,id', 'company_access.*.enabled' => 'required|boolean']);
        abort_if($user->is_platform_admin, 422, 'Platform administrator access is managed separately.');
        foreach ($data['company_access'] as $row) {
            $company = Company::findOrFail($row['company_id']);
            $original = $request->input('company_access');
            $row = collect($original)->firstWhere('company_id', $row['company_id']);
            if (!$row['enabled']) {
                CompanyMembership::where('company_id', $company->id)->where('user_id', $user->id)->update(['is_active' => false]);
                continue;
            }
            abort_unless($company->is_active, 422, 'Company is inactive.');
            app(CompanyContext::class)->run($company, function () use ($row, $company, $user, $request) {
                $assignments = MembershipAssignments::validate($row, MembershipAssignments::STAFF_ROLES);
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

    public static function rows(User $user): array
    {
        return CompanyMembership::where('user_id', $user->id)->get(['id', 'user_id', 'company_id', 'role_id', 'factory_id', 'is_active'])
            ->map(fn ($row) => ['company_id' => $row->company_id, 'role_id' => $row->role_id, 'factory_id' => $row->factory_id, 'enabled' => $row->is_active,
                'assignments' => app(CompanyContext::class)->run(Company::findOrFail($row->company_id), fn () => MembershipAssignments::rows($row))])->all();
    }
}
