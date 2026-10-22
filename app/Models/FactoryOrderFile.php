<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactoryOrderFile extends Model
{
    use HasFactory, \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['factory_order_id', 'path', 'original_name', 'quantity', 'material_type', 'thickness', 'dimensions', 'inspection_required'];

    public function factoryOrder(): BelongsTo
    {
        return $this->belongsTo(FactoryOrder::class);
    }
}
