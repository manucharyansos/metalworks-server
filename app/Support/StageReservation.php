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
    public static function reserve(User $actor, FactoryOrder $step, string $start, string $end, bool $allowWithoutShift = false): OperatorBooking
    {
        abort_if($start < CarbonImmutable::now('Asia/Yerevan')->format('Y-m-d H:i:s') || $end <= $start || substr($start, 0, 10) !== substr($end, 0, 10), 422, 'Ընտրեք ապագա միջակայք նույն աշխատանքային օրվա մեջ։');
        $step->task_scoped_operator ? TaskTeam::worker((int) $step->operator_id) : TaskRouting::operator((int) $step->operator_id, (int) $step->factory_id);
        $helpers = $step->helpers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        foreach ($helpers as $id) TaskTeam::worker($id);
        $participants = array_values(array_unique([(int) $step->operator_id, ...$helpers])); sort($participants);
        foreach ($participants as $id) self::lockOperator($id);
        foreach ($participants as $id) {
            $day = substr($start, 0, 10);
            $shifts = OperatorWorkInterval::where('operator_id', $id)
                ->where('starts_at', '<', $day.' 23:59:59')->where('ends_at', '>', $day.' 00:00:00')->lockForUpdate()->get();
            $inside = $shifts->contains(fn ($shift) => $shift->starts_at <= $start && $shift->ends_at >= $end);
            abort_unless($inside || ($allowWithoutShift && $shifts->isEmpty()), 422, 'Կատարողի կամ օգնականի ժամերը աշխատանքային գրաֆիկից դուրս են։');
            abort_if(OperatorBooking::active()->where('operator_id', $id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->lockForUpdate()->first(), 409, 'Կատարողը կամ օգնականը այս ժամին զբաղված է։ Թարմացրեք օրացույցը։');
        }
        // Only reserve after prerequisite work is complete or fully scheduled before this slot.
        foreach ($step->dependencyIds() as $id) {
            $previous = FactoryOrder::where('order_id', $step->order_id)->findOrFail($id);
            if ($previous->isWorkCompleted()) continue;
            $last = OperatorBooking::active()->where('factory_order_id', $id)->where('operator_id', $previous->operator_id)->max('ends_at');
            abort_unless($last && $last <= $start, 422, 'Նախ պլանավորեք նախորդ արտադրական քայլը։');
        }
        $row = OperatorBooking::create(['factory_order_id' => $step->id, 'operator_id' => $step->operator_id, 'creator_id' => $actor->id, 'starts_at' => $start, 'ends_at' => $end]);
        foreach ($helpers as $id) OperatorBooking::create(['factory_order_id' => $step->id, 'operator_id' => $id,
            'creator_id' => $actor->id, 'starts_at' => $start, 'ends_at' => $end, 'primary_booking_id' => $row->id]);
        OrderLog::create(['order_id' => $step->order_id, 'user_id' => $actor->id, 'action' => 'calendar.booked', 'message' => 'Պլանավորում՝ '.$start.' — '.$end]);
        return $row;
    }
}
