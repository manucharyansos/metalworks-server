<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = ['name', 'slug', 'logo_path', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function memberships()
    {
        return $this->hasMany(CompanyMembership::class);
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo' => $this->logo_path ? url("/api/companies/{$this->id}/logo") : null,
            'is_active' => $this->is_active,
        ];
    }
}
