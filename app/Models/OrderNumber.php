<?php

namespace App\Models;

use App\Support\OrderNumberGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderNumber extends Model
{
    protected $fillable = ['order_id', 'number'];

    protected static function booted(): void
    {
        static::creating(function (OrderNumber $orderNumber): void {
            // Centralize number generation so every creation path gets the
            // same concurrency-safe sequence, even if a controller passes a
            // legacy count-based number.
            $orderNumber->number = OrderNumberGenerator::next();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
