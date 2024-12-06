<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialGroup extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'image'];

    public function categories(): HasMany
    {
        return $this->hasMany(MaterialCategory::class);
    }

}
