<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Service extends Model
{
    protected $fillable = ['title','slug','image','description','sort','is_active'];
    protected $appends = ['image_url'];

    public function works(): BelongsToMany {
        return $this->belongsToMany(\App\Models\Work::class, 'service_work');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }
}
