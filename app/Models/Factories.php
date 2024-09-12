<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Factories extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'name'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
