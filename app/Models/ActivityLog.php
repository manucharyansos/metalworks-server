<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = [
        'user_id',
        'category',
        'action',
        'method',
        'route',
        'subject_type',
        'subject_id',
        'subject_label',
        'description',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
