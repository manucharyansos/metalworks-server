<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/** A position in a company's org chart; its holders get its access level and workshop. */
class CompanyPosition extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['parent_id', 'name', 'role_id', 'factory_id', 'description', 'sort_order'];
    protected $casts = ['parent_id' => 'integer', 'role_id' => 'integer', 'factory_id' => 'integer', 'sort_order' => 'integer'];

    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id'); }
    public function role(): BelongsTo { return $this->belongsTo(Role::class); }
    public function factory(): BelongsTo { return $this->belongsTo(Factory::class); }
    public function assignments(): HasMany { return $this->hasMany(MembershipAssignment::class, 'position_id'); }
}
