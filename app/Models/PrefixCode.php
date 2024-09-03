<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrefixCode extends Model
{
    use HasFactory;

    protected $fillable = ['code'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
