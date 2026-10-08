<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyMembership extends Model
{
    protected $fillable = ['company_id', 'user_id', 'role_id', 'factory_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::created(fn (self $membership) => $membership->syncLegacyAssignment());
        static::updated(function (self $membership): void {
            if ($membership->wasChanged('role_id') || $membership->wasChanged('factory_id')) $membership->syncLegacyAssignment();
        });
    }

    private function syncLegacyAssignment(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('membership_assignments')) return;
        $this->assignments()->delete();
        if ($this->role_id) $this->assignments()->create(['user_id' => $this->user_id, 'role_id' => $this->role_id, 'factory_id' => $this->factory_id]);
    }

    public function assignments() { return $this->hasMany(MembershipAssignment::class, 'membership_id'); }

    public function company() { return $this->belongsTo(Company::class); }
    public function role() { return $this->belongsTo(Role::class); }
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'membership_permissions', 'membership_id')
            ->withPivot('allowed')->withTimestamps();
    }
}
