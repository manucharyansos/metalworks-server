<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RemoteNumber extends Model
{
    use HasFactory;

    protected $fillable = ['remote_number', 'remote_number_name', 'pmp_id'];
    public function pmp(): BelongsTo
    {
        return $this->belongsTo(Pmp::class, 'pmp_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(PmpFiles::class, 'remote_number_id');
    }
}
