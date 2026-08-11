<?php

namespace App\Models;

use DateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'factory_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function hasPermission(string $slug): bool
    {
        if ($this->role && $this->role->name === 'admin') {
            return true;
        }

        $userOverride = $this->permissions()
            ->where('slug', $slug)
            ->first();

        if ($userOverride) {
            return (bool) $userOverride->pivot->allowed;
        }

        return $this->role
            ? $this->role->permissions()->where('slug', $slug)->exists()
            : false;
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
}
