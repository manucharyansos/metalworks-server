<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

class Service extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'title', 'slug', 'image', 'description',
        'video', 'video_poster',
    ];

    public $translatable = ['title', 'slug', 'description'];

    protected $appends = ['image_url', 'video_url', 'video_poster_url'];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    public function getVideoUrlAttribute(): ?string
    {
        return $this->video ? Storage::disk('public')->url($this->video) : null;
    }

    public function getVideoPosterUrlAttribute(): ?string
    {
        return $this->video_poster ? Storage::disk('public')->url($this->video_poster) : null;
    }
}
