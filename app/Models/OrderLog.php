<?php


namespace App\Models;

use DateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLog extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'action',
        'message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getCreatedAtAttribute($value): string
    {
        $dateTime = new DateTime($value);
        return $dateTime->format('d/m/Y');
    }
}
