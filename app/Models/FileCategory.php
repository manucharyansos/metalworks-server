<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class FileCategory extends Model
{
    use Concerns\BelongsToCompany;

    protected $fillable = ['name', 'code', 'legacy_factory_id', 'default_factory_id', 'is_reference', 'is_active'];
    protected $casts = ['is_reference' => 'boolean', 'is_active' => 'boolean'];

    public static function forLegacyFactory(int $id): ?self
    {
        $factory = Factory::find($id);
        if (!$factory) return null;
        $names = ['SW' => 'SW', 'DLD' => 'DLD', 'DXF' => 'DXF', 'IQS' => 'IGS', 'INFO' => 'INFO', 'PDF' => 'PDF'];
        return self::firstOrCreate(['legacy_factory_id' => $id], ['name' => $names[$factory->value] ?? $factory->name, 'code' => $factory->value ?: 'FILES_'.$id,
            'default_factory_id' => $factory->is_workshop && !$factory->is_reference ? $id : null,
            'is_reference' => $factory->is_reference, 'is_active' => true]);
    }

    public function defaultWorkshop(): BelongsTo { return $this->belongsTo(Factory::class, 'default_factory_id'); }
    public function extensions(): HasMany { return $this->hasMany(FactoryFileExtension::class, 'factory_id', 'legacy_factory_id'); }
    public function files(): HasMany { return $this->hasMany(PmpFiles::class, 'file_category_id'); }
}
