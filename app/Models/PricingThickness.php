<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One sheet thickness of a material with its laser cutting price. */
class PricingThickness extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['pricing_material_id', 'thickness', 'cut_price_per_m', 'pierce_price', 'is_active'];

    protected $casts = ['thickness' => 'float', 'cut_price_per_m' => 'float', 'pierce_price' => 'float', 'is_active' => 'boolean'];

    public function material(): BelongsTo
    {
        return $this->belongsTo(PricingMaterial::class, 'pricing_material_id');
    }
}
