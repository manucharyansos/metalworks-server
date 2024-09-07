<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrefixCode extends Model
{
    protected $fillable = ['order_id', 'code'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
