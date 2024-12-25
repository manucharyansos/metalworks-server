<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class File extends Model
{
    use HasFactory;

    protected $fillable = ['path', 'original_name', 'mime_type', 'order_id'];

    /**
     * Order relation
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the URL for the file
     */
    public function getUrlAttribute(): string
    {
        return asset("storage/{$this->path}");
    }

    /**
     * Get the MIME type of the file
     */
    public function getMimeTypeAttribute(): string
    {
        return mime_content_type(storage_path("app/public/{$this->path}"));
    }
}
