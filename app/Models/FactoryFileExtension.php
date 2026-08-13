<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactoryFileExtension extends Model
{
    use HasFactory;

    protected $fillable = [
        'factory_id',
        'extension',
    ];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }
}
