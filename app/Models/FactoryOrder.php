<?php

namespace App\Models;

use DateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function files()
    {
        return $this->belongsToMany(PmpFiles::class, 'factory_order_files')
                    ->withPivot(['quantity', 'material_type', 'thickness'])
                    ->withTimestamps();
    }

    public function getCreatedAtAttribute($value): string
    {
        return (new DateTime($value))->format('d/m/Y');
    }
}
