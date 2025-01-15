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

    protected $fillable = ['user_id', 'name', 'quantity', 'description', 'status'];

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
        return $this->belongsToMany(Factory::class, 'factory_order', 'order_id', 'factory_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function factoryOrderStatuses(): HasMany
    {
        return $this->hasMany(FactoryOrderStatus::class, 'order_id');
    }

    public function factoryFiles(): HasMany
    {
        return $this->hasMany(FactoryFile::class, 'order_id');
    }

    /**
     * @throws \Exception
     */
    public function getCreatedAtAttribute($value): string
    {
        $dateTime = new DateTime($value);
        return $dateTime->format('d/m/Y');
    }
}
