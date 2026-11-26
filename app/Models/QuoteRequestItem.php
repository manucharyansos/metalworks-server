<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One uploaded part of a customer's order request. */
class QuoteRequestItem extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = [
        'quote_request_id', 'path', 'original_name', 'size', 'workshop_ids', 'pricing_thickness_id', 'material', 'thickness',
        'assembly_key', 'assembly_position', 'quantity', 'bends', 'paint_ral', 'primer_required', 'note', 'metrics', 'pricing',
    ];

    protected $casts = [
        'workshop_ids' => 'array',
        'metrics' => 'array',
        'pricing' => 'array',
        'thickness' => 'decimal:2',
        'quantity' => 'integer',
        'bends' => 'integer',
        'size' => 'integer',
        'primer_required' => 'boolean',
    ];

    // The storage path stays on the server; clients get files through the API.
    protected $hidden = ['path'];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class, 'quote_request_id');
    }
}
