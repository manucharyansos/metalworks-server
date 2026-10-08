<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipAssignment extends Model
{
    protected $fillable = ['membership_id', 'user_id', 'role_id', 'factory_id'];
    protected $casts = ['membership_id' => 'integer', 'user_id' => 'integer', 'role_id' => 'integer', 'factory_id' => 'integer'];
    public function membership() { return $this->belongsTo(CompanyMembership::class); }
    public function role() { return $this->belongsTo(Role::class); }
    public function factory() { return $this->belongsTo(Factory::class); }
}
