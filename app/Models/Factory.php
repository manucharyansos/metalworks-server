<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Factory extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function orderStatuses(): HasMany
    {
        return $this->hasMany(FactoryOrderStatus::class);
    }

    public function factoryFiles(): HasMany
    {
        return $this->hasMany(FactoryFile::class);
    }
}
