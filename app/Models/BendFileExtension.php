<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BendFileExtension extends Model
{
    use HasFactory, \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['extension'];
}
