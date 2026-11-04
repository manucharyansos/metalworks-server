<?php
namespace App\Support;

use App\Models\{FactoryOrder, OperatorBooking, OperatorWorkInterval, OrderLog, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class StageReservation
{
    public static function lockOperator(int $id): void {
        abort_unless(DB::table('company_memberships')->where('company_id', app(CompanyContext::class)->id())
            ->where('user_id', $id)->where('is_active', true)->lockForUpdate()->first(), 422, 'Աշխատակիցը հասանելի չէ։');
    }

    // Call inside the transaction that owns the locked (or newly created) stage.
    public static function reserve(User $actor, FactoryOrder $step, string $start, string $end): OperatorBooking
    {
        abort_if($start < CarbonImmutable::now('Asia/Yerevan')->format('Y-m-d H:i:s') || $end <= $start || substr($start, 0, 10) !== substr($end, 0, 10), 422, 'Ընտրեք ապագա միջակայք նույն աշխատանքային օրվա մեջ։');
        TaskRouting::operator((int) $step->operator_id, (int) $step->factory_id);
        self::lockOperator((int) $step->operator_id);
        abort_unless(OperatorWorkInterval::where('operator_id', $step->operator_id)->where('starts_at', '<=', $start)->where('ends_at', '>=', $end)->lockForUpdate()->first(), 422, 'Ընտրված ժամերը աշխատանքային գրաֆիկից դուրս են։');
        abort_if(OperatorBooking::active()->where('operator_id', $step->operator_id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->lockForUpdate()->first(), 409, 'Այս ժամը զբաղված է։ Թարմացրեք օրացույցը։');
        // Only reserve after prerequisite work is complete or fully scheduled before this slot.
        foreach ($step->dependencyIds() as $id) {
            $previous = FactoryOrder::where('order_id', $step->order_id)->findOrFail($id);
            if ($previous->isWorkCompleted()) continue;
            $last = OperatorBooking::active()->where('factory_order_id', $id)->where('operator_id', $previous->operator_id)->max('ends_at');
            abort_unless($last && $last <= $start, 422, 'Նախ պլանավորեք նախորդ արտադրական քայլը։');
        }
        $row = OperatorBooking::create(['factory_order_id' => $step->id, 'operator_id' => $step->operator_id, 'creator_id' => $actor->id, 'starts_at' => $start, 'ends_at' => $end]);
        OrderLog::create(['order_id' => $step->order_id, 'user_id' => $actor->id, 'action' => 'calendar.booked', 'message' => 'Պլանավորում՝ '.$start.' — '.$end]);
        return $row;
    }
}
