<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations; // <— կարևորը

class Work extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'title', 'slug', 'description',
        'image', 'tags', 'is_published', 'sort_order',
    ];

    // Spatie
    public array $translatable = ['title','slug','description'];

    protected $casts = [
        'title'        => 'array',
        'slug'         => 'array',
        'description'  => 'array',
        'tags'         => 'array',
        'is_published' => 'boolean',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(WorkImage::class, 'work_id');
    }
}
