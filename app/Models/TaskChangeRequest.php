<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator's request to reject a workshop job or move its deadline.
 * It changes nothing until the engineer who created the task approves it.
 */
class TaskChangeRequest extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    public const REJECT = 'reject';
    public const DEADLINE = 'deadline';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'order_id', 'factory_order_id', 'requested_by', 'type', 'reason', 'operator_note', 'proposed_date',
        'previous_deadline', 'status', 'decided_by', 'decided_at', 'resolution', 'decision_note',
    ];

    protected $casts = [
        'proposed_date' => 'date:Y-m-d',
        'previous_deadline' => 'datetime',
        'decided_at' => 'datetime',
    ];

    protected $with = ['requester:id,name'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(FactoryOrder::class, 'factory_order_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }
}
