<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Factory extends Model
{
    use HasFactory, \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['name', 'value', 'is_reference', 'is_workshop', 'is_active'];

    protected $appends = ['is_reference', 'is_workshop'];
    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::created(function (self $factory) {
            if (in_array($factory->value, ['SW', 'DLD', 'DXF', 'IQS', 'INFO', 'PDF'], true)
                && \Illuminate\Support\Facades\Schema::hasTable('file_categories')) FileCategory::forLegacyFactory((int) $factory->id);
        });
    }

    public function getIsWorkshopAttribute($value): bool
    {
        return ($value === null || (bool) $value) && !$this->is_reference && !in_array(strtoupper(trim((string) $this->value)), self::nonWorkshopValues(), true);
    }

    public static function nonWorkshopValues(): array
    {
        return array_values(array_unique(array_map(fn ($value) => strtoupper(trim($value)), ['SW', 'INFO', 'PDF', ...config('companies.reference_factory_values', [])])));
    }

    // Archived production workshops remain valid in task history. File sections
    // and the engineer position never become production steps, even in old data.
    public function scopeWorkshops($query)
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('factories', 'is_workshop')) $query->where('factories.is_workshop', true)->where('factories.is_reference', false);
        return $query->where(fn ($q) => $q->whereNull('factories.value')->orWhereNotIn(\Illuminate\Support\Facades\DB::raw('UPPER(TRIM(factories.value))'), self::nonWorkshopValues()));
    }

    public function scopeActiveWorkshops($query)
    {
        $query->workshops();
        if (\Illuminate\Support\Facades\Schema::hasColumn('factories', 'is_active')) $query->where('factories.is_active', true);
        return $query;
    }

    public function getIsReferenceAttribute($value): bool
    {
        return (bool) $value || in_array(strtoupper((string) $this->value), config('companies.reference_factory_values', ['INFO', 'PDF']), true);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'factory_orders', 'factory_id', 'order_id')
            ->using(CompanyPivot::class)
            ->withPivot(['status', 'canceling', 'cancel_date', 'finish_date', 'operator_finish_date', 'admin_confirmation_date'])
            ->withTimestamps();
    }

    public function operators(): BelongsToMany
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('membership_assignments')) {
            return $this->belongsToMany(User::class, 'membership_assignments', 'factory_id', 'user_id')
                ->whereIn('membership_assignments.role_id', Role::whereIn('name', \App\Support\MembershipAssignments::OPERATORS)->select('id'))
                ->whereExists(function ($query) {
                    $query->selectRaw('1')->from('company_memberships as operator_membership')
                        ->whereColumn('operator_membership.id', 'membership_assignments.membership_id')
                        ->where('operator_membership.company_id', app(\App\Support\CompanyContext::class)->id() ?: $this->company_id)
                        ->where('operator_membership.is_active', true);
                })->distinct();
        }
        return $this->belongsToMany(User::class, 'company_memberships', 'factory_id', 'user_id')
            ->whereIn('company_memberships.role_id', Role::whereIn('name', \App\Support\MembershipAssignments::OPERATORS)->select('id'))
            ->wherePivot('company_id', app(\App\Support\CompanyContext::class)->id() ?: $this->company_id)
            ->wherePivot('is_active', true);
    }

    public function pmpFiles(): HasMany
    {
        return $this->hasMany(PmpFiles::class, 'factory_id');
    }

    public function fileExtensions(): HasMany
    {
        return $this->hasMany(FactoryFileExtension::class, 'factory_id');
    }
}
