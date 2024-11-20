<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Material extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'size', 'price', 'image', 'material_category_id'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(MaterialCategory::class);
    }
}
