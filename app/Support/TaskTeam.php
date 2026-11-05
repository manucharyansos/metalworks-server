<?php
namespace App\Support;
use App\Models\{User, FactoryOrder, Order, OrderLog, OperatorBooking};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskTeam {
    public static function workers() {
        return User::query()->forRoles(MembershipAssignments::OPERATORS)
            ->whereNotIn('users.id', User::query()->forRoles(['admin', 'manager', 'engineer', 'authenticatedUser'])->select('users.id'));
    }
    public static function worker(int $id): User {
        $user = self::workers()->whereKey($id)->first();
        if (!$user) throw ValidationException::withMessages(['operator_id' => ['Ընտրեք այս ընկերության ակտիվ արտադրական աշխատակցին։ Վարչակազմը և ինժիներները չեն կարող կցվել։']]);
        return $user;
    }
    public static function version(FactoryOrder $step): string {
        $ids = $step->helpers->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        return hash('sha256', json_encode([(int) $step->operator_id, $ids]));
    }
    public static function save(User $actor, FactoryOrder $step, array $data): FactoryOrder {
        return DB::transaction(function () use ($actor, $step, $data) {
            $order = Order::whereKey($step->order_id)->lockForUpdate()->firstOrFail();
            TaskRouting::authorize($actor, $order);
            $step = $order->factoryOrders()->whereKey($step->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(self::version($step), $data['team_version']), 409, 'Աշխատանքի կազմը փոխվել է։ Թարմացրեք էջը։');
            abort_if($step->isWorkCompleted() || $step->awaiting_engineer_confirmation || in_array($step->status, ['canceled', 'cancelled'], true) || in_array($order->status, ['canceled', 'cancelled'], true), 422, 'Այս աշխատանքի կազմը փոխել հնարավոր չէ։');
            $ids = array_map('intval', $data['helper_ids']); sort($ids);
            $operator = (int) $data['operator_id'];
            abort_if(in_array($operator, $ids, true), 422, 'Կատարողը չի կարող նաև օգնական լինել։');
            foreach ([$operator, ...$ids] as $id) self::worker($id);
            $old = $step->helpers->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ((int) $step->operator_id === $operator && $old === $ids) return $step;
            // Team changes cannot silently invalidate already reserved time for participants.
            abort_if(OperatorBooking::active()->where('factory_order_id', $step->id)->where('ends_at', '>', now('Asia/Yerevan')->format('Y-m-d H:i:s'))->lockForUpdate()->first(), 409, 'Նախ հանեք այս աշխատանքի ապագա ամրագրումները, ապա փոխեք կազմը։');
            $previous = $step->operator_id;
            $step->task_scoped_operator = true;
            $step->operator_id = $operator;
            $step->save();
            $step->helpers()->sync($ids);
            if ((int) $previous !== $operator) TaskChangeRequests::supersede($step, $actor);
            OrderLog::create(['order_id' => $order->id, 'user_id' => $actor->id, 'action' => 'task.team_changed',
                'message' => 'Աշխատանքի կատարողն ու օգնականները թարմացվել են։',
                'meta' => ['factory_order_id' => $step->id, 'operator_id' => $operator, 'helper_ids' => $ids]]);
            return $step->fresh();
        }, 3);
    }
}
