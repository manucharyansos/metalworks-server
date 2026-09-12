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
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Admin and manager are privileged roles and intentionally have access to
     * every business function. Other employee roles rely on individual
     * permission assignments from permission_user.
     */
    public function hasPermission(string $slug): bool
    {
        $roleName = $this->role?->name;

        if (in_array($roleName, ['admin', 'manager'], true)) {
            return true;
        }

        if (!$this->supportsPermissionAssignments()) {
            return false;
        }

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
        return self::$permissionAssignmentsSupported ??=
            Schema::hasTable('permission_user')
            && Schema::hasColumn('permission_user', 'allowed');
    }
}
