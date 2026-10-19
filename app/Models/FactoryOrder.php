<?php

namespace App\Models;

use DateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class FactoryOrder extends Model
{
    use HasFactory, \App\Models\Concerns\BelongsToCompany;

    protected $fillable = [
        'order_id',
        'factory_id',
        'status',
        'canceling',
        'cancel_date',
        'finish_date',
        'operator_finish_date',
        'admin_confirmation_date',
        'operator_id',
        'confirmation_required', 'confirmation_method', 'evidence_text', 'evidence_photo_path',
        'engineer_confirmation_at', 'engineer_confirmation_user_id', 'completed_at',
        'depends_on_id', 'return_note', 'returned_at', 'deadline', 'priority',
    ];

    protected $casts = ['confirmation_required' => 'boolean'];
    protected $hidden = ['evidence_photo_path', 'dependsOn'];
    protected $appends = ['awaiting_engineer_confirmation', 'has_evidence_photo', 'is_blocked'];

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(TaskChangeRequest::class, 'factory_order_id');
    }

    // At most one request waits for the engineer at a time.
    public function pendingRequest(): HasOne
    {
        return $this->hasOne(TaskChangeRequest::class, 'factory_order_id')->where('status', TaskChangeRequest::PENDING)->orderByDesc('id');
    }

    public function isWorkCompleted(): bool
    {
        return (bool) $this->completed_at || (in_array($this->status, ['finished', 'completed', 'done'], true)
            && (!$this->confirmation_required || $this->engineer_confirmation_at));
    }

    public function getIsBlockedAttribute(): bool
    {
        if (!$this->depends_on_id) return false;
        $previous = $this->dependsOn;
        if ($previous?->factory && !$previous->factory->is_workshop) return false;
        return !$previous?->isWorkCompleted();
    }

    public function getAwaitingEngineerConfirmationAttribute(): bool
    {
        return $this->confirmation_required && in_array($this->status, ['finished', 'completed', 'done'], true) && !$this->engineer_confirmation_at;
    }

    public function getHasEvidencePhotoAttribute(): bool { return (bool) $this->evidence_photo_path; }

    public function scopeAwaitingEngineer($query)
    {
        return $query->where('confirmation_required', true)->whereIn('status', ['finished', 'completed', 'done'])->whereNull('engineer_confirmation_at');
    }

    public function scopeForWorkshops($query)
    {
        return $query->whereHas('factory', fn ($factory) => $factory->workshops());
    }

    public function scopeVisibleOnBoard($query)
    {
        return $query->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>', now()->subHours(48)));
    }

    protected static function booted(): void
    {
        static::deleting(function (FactoryOrder $step): void {
            $step->changeRequests()->delete();
            TaskNotification::where('factory_order_id', $step->id)->delete();
        });

        static::saving(function (FactoryOrder $factoryOrder): void {
            if (!$factoryOrder->exists || $factoryOrder->isDirty('status')) {
                $status = $factoryOrder->status;

                if (
                    $status !== null &&
                    $status !== '' &&
                    !in_array($status, ['pending', 'waiting'], true) &&
                    !FactoryOrderStatus::where('value', $status)->exists()
                ) {
                    throw ValidationException::withMessages([
                        'factory_order.status' => ['Արտադրամասի կարգավիճակը թույլատրելի չէ։'],
                    ]);
                }
            }

            if (
                $factoryOrder->operator_id &&
                (!$factoryOrder->exists || $factoryOrder->isDirty('operator_id') || $factoryOrder->isDirty('factory_id'))
            ) {
                $operatorMatchesFactory = User::query()
                    ->whereKey($factoryOrder->operator_id)
                    ->assignedToFactory((int) $factoryOrder->factory_id)
                    ->exists();

                if (!$operatorMatchesFactory) {
                    throw ValidationException::withMessages([
                        'operator_id' => ['Ընտրված աշխատակիցը չի պատկանում այս արտադրամասին։'],
                    ]);
                }
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(PmpFiles::class, 'factory_order_files')
            ->using(CompanyPivot::class)
            ->withPivot(['quantity', 'material_type', 'thickness', 'task_file_revision_id'])
            ->withTimestamps();
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    public function getCreatedAtAttribute($value): string
    {
        return (new DateTime($value))->format('d/m/Y');
    }
}
