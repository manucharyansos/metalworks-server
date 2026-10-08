<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
                $validated = Validator::make($row, [
                    'role_id' => ['required', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereIn('name', ['admin', 'manager', 'engineer', 'laser', 'bend', 'powder_catting']))],
                    'factory_id' => 'nullable|integer|exists:factories,id',
                ])->validate();
                $factoryRole = in_array(Role::whereKey($validated['role_id'])->value('name'), ['laser', 'bend', 'powder_catting'], true);
                if ($factoryRole && empty($validated['factory_id'])) throw ValidationException::withMessages(['company_access' => ['Select a workshop for each operator.']]);
                $membership = CompanyMembership::firstOrNew(['company_id' => $company->id, 'user_id' => $user->id]);
                $roleChanged = $membership->exists && (int) $membership->role_id !== (int) $validated['role_id'];
                $membership->fill(['role_id' => $validated['role_id'], 'factory_id' => $factoryRole ? $validated['factory_id'] : null, 'is_active' => true])->save();
                if ($roleChanged) $membership->permissions()->detach();
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
        return CompanyMembership::where('user_id', $user->id)->get(['company_id', 'role_id', 'factory_id', 'is_active'])
            ->map(fn ($row) => ['company_id' => $row->company_id, 'role_id' => $row->role_id, 'factory_id' => $row->factory_id, 'enabled' => $row->is_active])->all();
    }
}
