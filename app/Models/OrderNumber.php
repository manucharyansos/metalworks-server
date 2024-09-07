<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderNumber extends Model
{
    protected $fillable = ['order_id', 'number'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
