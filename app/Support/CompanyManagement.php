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
}
