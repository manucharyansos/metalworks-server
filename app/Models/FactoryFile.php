<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactoryFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'factory_order_id',
        'path',
        'original_name',
    ];

    public function factoryOrder(): BelongsTo
    {
        return $this->belongsTo(FactoryOrder::class);
    }
}
