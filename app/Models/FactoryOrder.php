<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactoryOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'factory_id',
        'order_id',
        'status',
        'path',
        'original_name',
        'canceling',
        'cancel_date',
        'finish_date',
        'operator_finish_date',
        'admin_confirmation_date'
    ];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
