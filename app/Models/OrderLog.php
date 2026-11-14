<?php


namespace App\Models;

use DateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLog extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
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

    // created_at is only the d/m/Y day; the history shows the hour too.
    protected $appends = ['created_time'];

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

    /** The moment with its zone, for the history that shows the hour. */
    public function getCreatedTimeAttribute(): ?string
    {
        $raw = $this->attributes['created_at'] ?? null;
        return $raw ? $this->asDateTime($raw)->toIso8601String() : null;
    }
}
