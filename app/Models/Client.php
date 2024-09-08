<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'number',
        'AVC',
        'group',
        'VAT_payer',
        'legal_address',
        'valid_address',
        'VAT_of_the_manager',
        'leadership_position',
        'accountants_VAT',
        'accountant_position',
        'registration_of_the_individual',
        'type_of_ID_card',
        'passport_number',
        'email_address',
        'contract',
        'contract_date',
        'sales_discount_percentage',
        'email_address',
        'user_id'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
