<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Work extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'slug', 'description',
        'image',
        'tags',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_published' => 'boolean',
    ];

    protected $appends = ['image_url'];


    public function images(): HasMany
    {
        return $this->hasMany(WorkImage::class, 'work_id');
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : null;
    }
}
