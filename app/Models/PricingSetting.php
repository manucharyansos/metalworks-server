<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The company's service prices and estimate rules (one row per company). */
class PricingSetting extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = [
        'enabled', 'bend_price', 'paint_price_m2', 'primer_price_m2', 'paint_min_part', 'setup_fee',
        'min_order', 'waste_percent', 'discounts', 'vat_included', 'customer_note',
    ];

    protected $casts = [
        'enabled' => 'boolean', 'vat_included' => 'boolean', 'discounts' => 'array',
        'bend_price' => 'float', 'paint_price_m2' => 'float', 'primer_price_m2' => 'float', 'paint_min_part' => 'float',
        'setup_fee' => 'float', 'min_order' => 'float', 'waste_percent' => 'float',
    ];

    /** The company's row, or unsaved defaults when prices were never set. */
    public static function current(): self
    {
        return self::query()->first() ?? new self([
            'enabled' => false, 'bend_price' => 0, 'paint_price_m2' => 0, 'primer_price_m2' => 0, 'paint_min_part' => 0,
            'setup_fee' => 0, 'min_order' => 0, 'waste_percent' => 10, 'discounts' => [], 'vat_included' => true,
        ]);
    }
}
