<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PmpFiles extends Model
{
    use HasFactory, \App\Models\Concerns\BelongsToCompany;

    protected $fillable = [
        'pmp_id',
        'remote_number_id',
        'factory_id',
        'file_category_id',
        'path',
        'original_name',
        'quantity',
        'material_type',
        'thickness',
        'dimensions',
        'production_factory_ids',
        'inspection_required',
    ];

    protected $casts = ['production_factory_ids' => 'array', 'inspection_required' => 'boolean'];

    protected $with = ['category.defaultWorkshop'];
    protected $appends = ['is_reference_file', 'routing_factory_id', 'routing_factory_ids'];

    protected static function booted(): void
    {
        static::saving(function (self $file) {
            if (!\Illuminate\Support\Facades\Schema::hasTable('file_categories')) return;
            if (!$file->file_category_id && $file->factory_id) $file->file_category_id = FileCategory::forLegacyFactory((int) $file->factory_id)?->id;
            if ($file->file_category_id) {
                $category = FileCategory::findOrFail($file->file_category_id);
                if ((int) $file->factory_id !== (int) $category->legacy_factory_id) throw \Illuminate\Validation\ValidationException::withMessages(['file_category_id' => ['Ֆայլի բաժինը չի համապատասխանում ընտրված ֆայլին։']]);
            }
        });
    }

    public function category(): BelongsTo { return $this->belongsTo(FileCategory::class, 'file_category_id'); }
    public function getIsReferenceFileAttribute(): bool { return $this->category ? $this->category->is_reference : (bool) $this->factory?->is_reference; }
    public function getRoutingFactoryIdAttribute(): ?int
    {
        if ($this->production_factory_ids !== null) return $this->production_factory_ids[0] ?? null;
        $factory = $this->category ? $this->category->defaultWorkshop : $this->factory;
        return $factory && $factory->is_workshop && $factory->is_active !== false && !$factory->is_reference ? (int) $factory->id : null;
    }

    public function getRoutingFactoryIdsAttribute(): array
    {
        return $this->production_factory_ids !== null ? array_map('intval', $this->production_factory_ids)
            : ($this->routing_factory_id ? [(int) $this->routing_factory_id] : []);
    }

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

    public function selectedFiles(): HasMany
    {
        return $this->hasMany(SelectedFile::class, 'pmp_file_id');
    }
}
