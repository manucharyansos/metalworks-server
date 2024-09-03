<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreLink extends Model
{
    use HasFactory;

    protected $fillable = ['url'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
