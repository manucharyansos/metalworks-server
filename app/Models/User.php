<?php

namespace App\Models;

use DateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use App\Support\PermissionScope;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    private static ?bool $permissionAssignmentsSupported = null;

    protected $fillable = [
        'name',
        'last_name',
        'patronymic',
        'email',
        'phone',
        'address',
        'password',
        'role_id',
        'factory_id',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_platform_admin' => 'boolean',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $id = app(CompanyContext::class)->id();
            if ($id) $query->whereIn('users.id', function ($q) use ($id) {
                $q->select('user_id')->from('company_memberships')->where('company_id', $id);
            });
        });
        static::created(function (User $user): void {
            $context = app(CompanyContext::class);
            if (!$context->id()) return;
            CompanyMembership::create([
                'company_id' => $context->id(), 'user_id' => $user->id,
                'role_id' => $user->getAttributes()['role_id'] ?? null,
                'factory_id' => $user->getAttributes()['factory_id'] ?? null, 'is_active' => true,
            ]);
            $context->forgetMembership($user->id);
        });
        static::updated(function (User $user): void {
            $context = app(CompanyContext::class);
            if (!$context->id() || (!$user->wasChanged('role_id') && !$user->wasChanged('factory_id'))) return;
            $membership = $context->membership($user->id);
            if (!$membership) return;
            $attributes = $user->getAttributes();
            $membership->update(['role_id' => $attributes['role_id'] ?? null, 'factory_id' => $attributes['factory_id'] ?? null]);
            if ($user->wasChanged('role_id')) $membership->permissions()->detach();
            $context->forgetMembership($user->id);
            $user->unsetRelation('role')->unsetRelation('factory');
        });
    }

    public function getRoleIdAttribute($value): ?int
    {
        $context = app(CompanyContext::class);
        if (!$context->id() || !$this->id) return $value === null ? null : (int) $value;
        if ($this->is_platform_admin) return (int) Role::where('name', 'admin')->value('id');
        return $context->membership($this->id)?->role_id;
    }

    public function getFactoryIdAttribute($value): ?int
    {
        $context = app(CompanyContext::class);
        if (!$context->id() || !$this->id) return $value === null ? null : (int) $value;
        if ($this->is_platform_admin) return null;
        return $context->membership($this->id)?->factory_id;
    }

    public function scopeForRoles(Builder $query, array $names, bool $exclude = false): Builder
    {
        $id = app(CompanyContext::class)->id();
        if (!$id) return $query->whereHas('role', fn ($q) => $exclude ? $q->whereNotIn('name', $names) : $q->whereIn('name', $names));
        return $query->whereIn('users.id', function ($q) use ($id, $names, $exclude) {
            $q->select('cm.user_id')->from('company_memberships as cm')->join('roles as cr', 'cr.id', '=', 'cm.role_id')
                ->where('cm.company_id', $id)->where('cm.is_active', true);
            $exclude ? $q->whereNotIn('cr.name', $names) : $q->whereIn('cr.name', $names);
        });
    }

    public function scopeAssignedToFactory(Builder $query, ?int $factoryId = null): Builder
    {
        $id = app(CompanyContext::class)->id();
        if (!$id) return $factoryId ? $query->where('factory_id', $factoryId) : $query->whereNotNull('factory_id');
        return $query->whereIn('users.id', function ($q) use ($id, $factoryId) {
            $q->select('user_id')->from('company_memberships')->where('company_id', $id)->where('is_active', true);
            $factoryId ? $q->where('factory_id', $factoryId) : $q->whereNotNull('factory_id');
        });
    }

    public function memberships() { return $this->hasMany(CompanyMembership::class); }
    public function getCurrentMembershipIdAttribute(): ?int { return app(CompanyContext::class)->membership($this->id)?->id; }

    /**
     * Admin and manager are privileged roles and intentionally have access to
     * every business function. Other employee roles rely on individual
     * permission assignments from permission_user.
     */
    public function hasPermission(string $slug): bool
    {
        $context = app(CompanyContext::class);
        if ($context->id() && !$this->is_platform_admin && !$context->membership($this->id)?->is_active) return false;
        $roleName = $this->role?->name;

        if (PermissionScope::isFullAccess($roleName)) {
            return true;
        }

        // Individual grants only work inside the employee role's real workspace.
        // This prevents stale or manually inserted permissions from opening
        // unrelated manager/engineer/factory functionality.
        if (!PermissionScope::allows($roleName, $slug)) {
            return false;
        }

        if (!$this->supportsPermissionAssignments()) {
            return false;
        }

        if ($context->id()) return $this->permissions()->wherePivot('allowed', true)->where('slug', $slug)->exists();

        return DB::table('permission_user')
            ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->where('permission_user.user_id', $this->id)
            ->where('permissions.slug', $slug)
            ->where('permission_user.allowed', true)
            ->exists();
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class, 'user_id');
    }

    public function worker(): HasOne
    {
        return $this->hasOne(Worker::class);
    }

    public function permissions(): BelongsToMany
    {
        if (app(CompanyContext::class)->id()) {
            return $this->belongsToMany(Permission::class, 'membership_permissions', 'membership_id', 'permission_id', 'current_membership_id')
                ->withPivot('allowed')->withTimestamps();
        }
        return $this->belongsToMany(Permission::class)
            ->withPivot('allowed')
            ->withTimestamps();
    }

    public function getCreatedAtAttribute($value): string
    {
        $dateTime = new DateTime($value);
        return $dateTime->format('d/m/Y');
    }

    private function supportsPermissionAssignments(): bool
    {
        if (app(CompanyContext::class)->id()) return Schema::hasTable('membership_permissions');
        return self::$permissionAssignmentsSupported ??=
            Schema::hasTable('permission_user')
            && Schema::hasColumn('permission_user', 'allowed');
    }
}
