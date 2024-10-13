<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class File extends Model
{
    use HasFactory;

    use HasFactory;

    protected $fillable = ['path'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
