<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Factory extends Model
{
    use HasFactory, \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['name', 'value'];

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'factory_orders', 'factory_id', 'order_id')
            ->using(CompanyPivot::class)
            ->withPivot(['status', 'canceling', 'cancel_date', 'finish_date', 'operator_finish_date', 'admin_confirmation_date'])
            ->withTimestamps();
    }

    public function operators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_memberships', 'factory_id', 'user_id')
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
