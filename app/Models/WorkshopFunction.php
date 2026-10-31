<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Extra work a workshop can do on a part (engraving, deburring, threading…). */
class WorkshopFunction extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['factory_id', 'name', 'position', 'is_active'];
    protected $casts = ['factory_id' => 'integer', 'position' => 'integer', 'is_active' => 'boolean'];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }
}
