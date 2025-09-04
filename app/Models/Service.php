<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'title','slug','image','description','sort','is_active',
        'video','video_poster'
    ];

    protected $appends = ['image_url','video_url','video_poster_url'];

    public function works(): BelongsToMany {
        return $this->belongsToMany(Work::class, 'service_work');
    }

    public function getImageUrlAttribute(): ?string {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }
    public function getVideoUrlAttribute(): ?string {
        return $this->video ? Storage::disk('public')->url($this->video) : null;
    }
    public function getVideoPosterUrlAttribute(): ?string {
        return $this->video_poster ? Storage::disk('public')->url($this->video_poster) : null;
    }
}
