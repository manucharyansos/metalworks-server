<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'material_type_id'];

    public function type(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }
}