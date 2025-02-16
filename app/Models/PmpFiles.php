<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmpFiles extends Model
{
    use HasFactory;

    use HasFactory;

    protected $fillable = [
        'pmp_id',
        'remote_number_id',
        'factory_id',
        'path',
        'original_name',
    ];

    public function pmp(): BelongsTo
    {
        return $this->belongsTo(Pmp::class, 'pmp_id');
    }

    public function remoteNumber(): BelongsTo
    {
        return $this->belongsTo(RemoteNumber::class, 'remote_number_id');
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }
}
