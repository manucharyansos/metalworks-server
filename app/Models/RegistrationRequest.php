<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationRequest extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = [
        'name', 'last_name', 'patronymic', 'email', 'password_hash', 'type',
        'job_title', 'status', 'existing_user_id', 'user_id', 'reviewed_by', 'reviewed_at',
    ];

    protected $hidden = ['password_hash', 'existing_user_id'];
    protected $casts = ['reviewed_at' => 'datetime'];
}
