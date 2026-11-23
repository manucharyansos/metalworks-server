<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer's order request; see App\Support\QuoteRequests for its life cycle. */
class QuoteRequest extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    public const STATUSES = ['draft', 'submitted', 'quoted', 'accepted', 'declined', 'rejected', 'cancelled'];

    protected $fillable = [
        'customer_id', 'number', 'status', 'title', 'note', 'desired_date', 'phone', 'submitted_at', 'estimate',
        'price', 'currency', 'ready_date', 'response_note', 'responded_by', 'responded_at', 'decided_at',
    ];

    protected $casts = [
        'estimate' => 'array',
        'desired_date' => 'date:Y-m-d',
        'ready_date' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'responded_at' => 'datetime',
        'decided_at' => 'datetime',
        'price' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QuoteRequestItem::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
