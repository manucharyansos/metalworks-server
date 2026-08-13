<?php

namespace App\Models;

use DateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

class FactoryOrder extends Model
{
    use HasFactory;

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
    ];

    protected static function booted(): void
    {
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
                    ->where('factory_id', $factoryOrder->factory_id)
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
            ->withPivot(['quantity', 'material_type', 'thickness'])
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
