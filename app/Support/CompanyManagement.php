<?php

namespace App\Support;

use App\Models\{Company, User};
use Illuminate\Database\Eloquent\Collection;

final class CompanyManagement
{
    public static function companies(User $actor): Collection
    {
        $query = Company::where('is_active', true)->orderBy('id');
        if (!$actor->is_platform_admin) {
            $query->whereHas('memberships', fn ($memberships) => $memberships
                ->where('user_id', $actor->id)->where('is_active', true)
                ->where(function ($roles) {
                    $roles->whereHas('role', fn ($role) => $role->whereIn('name', ['admin', 'manager']))
                        ->orWhereHas('assignments.role', fn ($role) => $role->whereIn('name', ['admin', 'manager']));
                }));
        }
        return $query->get();
    }

    public static function unassignedDirectory(Collection $managed): Collection
    {
        // Company names are already public on the registration directory. No
        // membership, workshop or request data is exposed for these companies.
        return Company::where('is_active', true)->whereNotIn('id', $managed->modelKeys())
            ->orderBy('id')->get(['id', 'name']);
    }
}
