<?php

namespace App\Models;

use DateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'description', 'status', 'link_existing_files', 'creator_id', 'remote_number_id'];

    protected $casts = [
        'link_existing_files' => 'boolean',
    ];
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeVisibleTo($q, User $user)
    {
        $isAdmin = optional($user->role)->name === 'admin';
        return $isAdmin ? $q : $q->where('creator_id', $user->id);
    }

    public function selectedFiles(): HasMany
    {
        return $this->hasMany(SelectedFile::class);
    }

    public function orderNumber(): HasOne
    {
        return $this->hasOne(OrderNumber::class);
    }

    public function prefixCode(): HasOne
    {
        return $this->hasOne(PrefixCode::class);
    }

    public function storeLink(): HasOne
    {
        return $this->hasOne(StoreLink::class);
    }

    public function dates(): HasOne
    {
        return $this->hasOne(Date::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function factories(): BelongsToMany
    {
        return $this->belongsToMany(Factory::class, 'factory_orders', 'order_id', 'factory_id')
            ->withPivot(['status', 'canceling', 'cancel_date', 'finish_date', 'operator_finish_date', 'admin_confirmation_date'])
            ->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function factoryOrders(): HasMany
    {
        return $this->hasMany(FactoryOrder::class, 'order_id');
    }

    public function getCreatedAtAttribute($value): string
    {
        return (new DateTime($value))->format('d/m/Y');
    }
}
