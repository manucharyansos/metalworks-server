<?php

namespace App\Models;

use DateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id'
    ];

    public function orderNumber(): HasOne
    {
        return $this->hasOne(OrderNumber::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(Detail::class);
    }

    public function status(): HasOne
    {
        return $this->hasOne(Status::class);
    }

    public function prefixCode(): HasOne
    {
        return $this->hasOne(PrefixCode::class);
    }

    public function storeLink(): HasOne
    {
        return $this->hasOne(StoreLink::class);
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