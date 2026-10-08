<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CompanyPivot extends Pivot
{
    use BelongsToCompany;
    protected $guarded = [];
}
