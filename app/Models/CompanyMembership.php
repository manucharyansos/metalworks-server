<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyMembership extends Model
{
    protected $fillable = ['company_id', 'user_id', 'role_id', 'factory_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function company() { return $this->belongsTo(Company::class); }
    public function role() { return $this->belongsTo(Role::class); }
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'membership_permissions', 'membership_id')
            ->withPivot('allowed')->withTimestamps();
    }
}
