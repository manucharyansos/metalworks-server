<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OperatorWorkInterval extends Model {
    use Concerns\BelongsToCompany;
    protected $guarded = ['id', 'company_id'];
}
