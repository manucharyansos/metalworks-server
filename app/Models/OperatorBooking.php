<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OperatorBooking extends Model {
    use Concerns\BelongsToCompany;
    protected $guarded = ['id', 'company_id'];
    public function step() { return $this->belongsTo(FactoryOrder::class, 'factory_order_id'); }
    public function scopeActive($q) {
        return $q->whereHas('step', fn ($q) => $q->whereNull('completed_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['finished', 'completed', 'done', 'canceled', 'cancelled']))
            ->whereHas('order', fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['canceled', 'cancelled'])));
    }
}
