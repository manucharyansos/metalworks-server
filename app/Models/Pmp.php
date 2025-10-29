<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pmp extends Model
{
    use HasFactory;

    protected $fillable = ['group', 'group_name', 'admin_confirmation'];

    public function remoteNumber(): HasMany
    {
        return $this->hasMany(RemoteNumber::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(PmpFiles::class, 'pmp_id');
    }
}
