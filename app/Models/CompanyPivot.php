<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CompanyPivot extends Pivot
{
    use BelongsToCompany;
    protected $guarded = [];
    // Task file rows keep the names of the workshop functions chosen for them.
    protected $casts = ['production_functions' => 'array'];
}
