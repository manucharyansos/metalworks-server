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

class Order extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'description', 'status', 'link_existing_files', 'creator_id', 'remote_number_id'];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->assertCustomerUser();
        });

        static::updating(function (Order $order): void {
            if ($order->isDirty('user_id')) {
                $order->assertCustomerUser();
            }
        });
    }

    public function logs(): HasMany
    {
        return $this->hasMany(OrderLog::class)->latest();
    }

    protected $casts = [
        'link_existing_files' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeVisibleTo($q, User $user)
    {
        $isAdmin = optional($user->role)->name === 'admin';
        return $isAdmin ? $q : $q->where('creator_id', $user->id);
    }

    public function selectedFiles(): HasMany
    {
        return $this->hasMany(SelectedFile::class);
    }

    public function orderNumber(): HasOne
    {
        return $this->hasOne(OrderNumber::class);
    }

    public function prefixCode(): HasOne
    {
        return $this->hasOne(PrefixCode::class);
    }

    public function storeLink(): HasOne
    {
        return $this->hasOne(StoreLink::class);
    }

    public function dates(): HasOne
    {
        return $this->hasOne(Date::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function factories(): BelongsToMany
    {
        return $this->belongsToMany(Factory::class, 'factory_orders', 'order_id', 'factory_id')
            ->withPivot(['status', 'canceling', 'cancel_date', 'finish_date', 'operator_finish_date', 'admin_confirmation_date'])
            ->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class, 'user_id', 'user_id');
    }

    public function factoryOrders(): HasMany
    {
        return $this->hasMany(FactoryOrder::class, 'order_id');
    }

    public function getCreatedAtAttribute($value): string
    {
        return (new DateTime($value))->format('d/m/Y');
    }

    public function updateStatusIfAllFactoriesAdminConfirmed(): void
    {
        $factoryOrders = $this->factoryOrders;

        if ($factoryOrders->isEmpty()) {
            return;
        }

        $allConfirmed = $factoryOrders->every(function ($fo) {
            $status = strtolower($fo->status ?? '');

            $isFinished = in_array($status, ['finished', 'completed', 'done', 'confirmed'], true);
            $isCanceled = in_array($status, ['canceled', 'cancelled'], true);

            return ($isFinished && !is_null($fo->admin_confirmation_date)) || $isCanceled;
        });

        if ($allConfirmed) {
            $this->status = 'completed';
            $this->completed_at = now();
            $this->save();
        }
    }

    private function assertCustomerUser(): void
    {
        $isCustomer = User::query()
            ->whereKey($this->user_id)
            ->whereHas('role', fn ($query) => $query->where('name', 'authenticatedUser'))
            ->exists();

        if (!$isCustomer) {
            throw ValidationException::withMessages([
                'user_id' => ['Պատվերի հաճախորդը պետք է լինի գրանցված հաճախորդի հաշիվ։'],
            ]);
        }
    }
}
