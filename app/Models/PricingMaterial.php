<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A sheet material of the company's price list. */
class PricingMaterial extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['name', 'density', 'price_per_kg', 'is_active', 'sort'];

    protected $casts = ['density' => 'float', 'price_per_kg' => 'float', 'is_active' => 'boolean', 'sort' => 'integer'];

    public function thicknesses(): HasMany
    {
        return $this->hasMany(PricingThickness::class)->orderBy('thickness');
    }
}
