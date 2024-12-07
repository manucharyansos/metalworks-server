<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactoryOrderStatus extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'factory_id', 'status', 'canceling'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

}
