<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskFileRevision extends Model
{
    use Concerns\BelongsToCompany;
    protected $fillable = ['order_id', 'pmp_file_id', 'user_id', 'version', 'file_path', 'original_name', 'file_size', 'mime_type', 'note'];
    protected $hidden = ['file_path'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function file(): BelongsTo { return $this->belongsTo(PmpFiles::class, 'pmp_file_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
