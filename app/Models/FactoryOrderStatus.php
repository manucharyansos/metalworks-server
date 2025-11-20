<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FactoryOrderStatus extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'name', 'value', 'color', 'icon', 'requires_reason', 'sort_order', 'is_active'];
}
