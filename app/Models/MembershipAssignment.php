<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipAssignment extends Model
{
    protected $fillable = ['membership_id', 'user_id', 'role_id', 'factory_id', 'position_id'];
    protected $casts = ['membership_id' => 'integer', 'user_id' => 'integer', 'role_id' => 'integer', 'factory_id' => 'integer', 'position_id' => 'integer'];
    public function membership() { return $this->belongsTo(CompanyMembership::class); }
    public function role() { return $this->belongsTo(Role::class); }
    public function factory() { return $this->belongsTo(Factory::class); }
    /** The org chart position this assignment holds, if the employee was placed in one. */
    public function position() { return $this->belongsTo(CompanyPosition::class); }
}
