<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;
    protected $fillable = [
        'order_number', 'description_id', 'creator_id', 'prefix_code_id', 'store_link_id', 'status_id'
    ];

    public function description(): BelongsTo
    {
        return $this->belongsTo(Description::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    public function prefixCode(): BelongsTo
    {
        return $this->belongsTo(PrefixCode::class);
    }

    public function storeLink(): BelongsTo
    {
        return $this->belongsTo(StoreLink::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

}
