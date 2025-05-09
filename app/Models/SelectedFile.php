<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


class SelectedFile extends Model
{
    protected $table = 'selected_files';

    protected $fillable = [
        'order_id',
        'pmp_file_id',
        'quantity',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function pmpFile(): BelongsTo
    {
        return $this->belongsTo(PmpFiles::class, 'pmp_file_id');
    }
}
