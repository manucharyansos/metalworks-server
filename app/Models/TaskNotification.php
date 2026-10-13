<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskNotification extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['user_id', 'actor_id', 'type', 'order_id', 'factory_order_id', 'message', 'data', 'read_at'];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];
}
