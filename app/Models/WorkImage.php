<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WorkImage extends Model
{
    use HasFactory;

    protected $fillable = ['work_id', 'path', 'alt'];
    protected $appends = ['url'];

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : null;
    }
}
