<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Factory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'value'];

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'factory_orders', 'factory_id', 'order_id')
            ->withPivot(['status', 'canceling', 'cancel_date', 'finish_date', 'operator_finish_date', 'admin_confirmation_date'])
            ->withTimestamps();
    }

    public function pmpFiles(): HasMany
    {
        return $this->hasMany(PmpFiles::class, 'factory_id');
    }
}
