<?php
namespace App\Http\Controllers\Api\Order;
use App\Http\Controllers\Controller;
use App\Models\{FactoryOrder, OperatorBooking, OperatorWorkInterval, User, OrderLog};
use App\Support\{CompanyContext, MembershipAssignments, TaskRouting};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperatorCalendarController extends Controller
{
    private function authorizeRead(Request $request): void {
        abort_unless(in_array($request->user()->role?->name, ['admin', 'manager', 'engineer'], true), 403);
        abort_unless($request->user()->role?->name !== 'engineer' || $request->user()->hasPermission('orders.view') || $request->user()->hasPermission('orders.create'), 403);
    }
    private function manager(Request $request): void { abort_unless(in_array($request->user()->role?->name, ['admin', 'manager'], true), 403); }
    private function editable(User $user, $order): bool {
        return in_array($user->role?->name, ['admin', 'manager'], true) || ((int) $order->creator_id === (int) $user->id && $user->hasPermission('orders.update'));
    }
    // The Yerevan day a stage's work begins: when it became ready (for a stage
    // still waiting on earlier workshops, the latest of their deadlines), or the
    // later day it was planned for.
    private function startsOn(FactoryOrder $step, $open): ?string {
        $handoff = null;
        if ($step->is_blocked) {
            $handoff = collect($step->dependencyIds())->map(fn ($id) => $open->get($id))->filter()
                ->map(fn ($before) => substr((string) ($before->deadline ?: $before->order->dates?->finish_date), 0, 10))
                ->filter()->max();
        }
        if (!$handoff) {
            // created_at's public accessor returns d/m/Y for display. Use the stored
            // timestamp so legacy stages retain their date and time before conversion.
            $since = $step->ready_at ?? $step->getRawOriginal('created_at');
            $handoff = $since ? CarbonImmutable::parse($since)->setTimezone('Asia/Yerevan')->toDateString() : null;
        }
        $planned = $step->planned_start ? substr((string) $step->planned_start, 0, 10) : null;
        return $planned && (!$handoff || $planned > $handoff) ? $planned : $handoff;
    }
    private function times(Request $request): array {
        $data = $request->validate(['starts_at' => 'required|date_format:Y-m-d H:i', 'ends_at' => 'required|date_format:Y-m-d H:i|after:starts_at']);
        abort_unless(substr($data['starts_at'], 0, 10) === substr($data['ends_at'], 0, 10), 422, 'Յուրաքանչյուր օրվա ժամերը նշեք առանձին։');
        return [$data['starts_at'].':00', $data['ends_at'].':00'];
    }
    /** The project and item names behind an order code such as 990.01, when the order has them. */
    private function title(\App\Models\Order $order): ?string
    {
        $item = $order->remoteNumber;
        $names = array_values(array_unique(array_filter([trim((string) $item?->pmp?->group_name), trim((string) $item?->remote_number_name)])));
        return $names ? implode(' · ', $names) : null;
    }
    public function index(Request $request) {
        $this->authorizeRead($request);
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        abort_if(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 30, 422);
        $from = $data['from'].' 00:00:00'; $to = $data['to'].' 23:59:59';
        $workload = TaskRouting::workload();
        $shifts = OperatorWorkInterval::where('starts_at', '<', $to)->where('ends_at', '>', $from)->orderBy('starts_at')->get();
        $bookings = OperatorBooking::active()->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->with(['step.order.creator', 'step.order.orderNumber', 'step.order.remoteNumber.pmp', 'step.factory', 'step.helpers'])->orderBy('starts_at')->get()->map(fn ($b) => [
                'id' => $b->id, 'operator_id' => $b->operator_id, 'factory_order_id' => $b->factory_order_id,
                'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at,
                'task' => $b->step->order->name, 'title' => $this->title($b->step->order), 'order_id' => $b->step->order_id, 'order_number' => $b->step->order->orderNumber?->number,
                'workshop' => $b->step->factory->name,
                'engineer' => $b->step->order->creator?->name, 'blocked' => $b->step->is_blocked,
                'is_helper' => (bool) $b->primary_booking_id,
                'assignment_changed' => $b->primary_booking_id ? !$b->step->helpers->contains('id', $b->operator_id) : (int) $b->step->operator_id !== (int) $b->operator_id,
                'can_edit' => $this->editable($request->user(), $b->step->order),
            ]);
        $steps = FactoryOrder::forWorkshops()->whereNull('completed_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['finished', 'completed', 'done', 'canceled', 'cancelled']))
            ->whereHas('order', fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['canceled', 'cancelled']))
            ->with(['order.creator', 'order.dates', 'order.orderNumber', 'order.remoteNumber.pmp', 'operator:id,name', 'factory', 'helpers'])->orderBy('id')->get();
        $future = OperatorBooking::active()->where('ends_at', '>', CarbonImmutable::now('Asia/Yerevan')->format('Y-m-d H:i:s'))->get();
        $open = $steps->keyBy('id');
        return response()->json(['timezone' => 'Asia/Yerevan', 'factories' => $workload['factories'], 'shifts' => $shifts, 'bookings' => $bookings,
            'can_manage_shifts' => in_array($request->user()->role?->name, ['admin', 'manager'], true),
            'workers' => \App\Support\TaskTeam::workers()->orderBy('name')->get(['users.id', 'users.name']),
            'tasks' => $steps->map(fn ($s) => ['id' => $s->id, 'operator_id' => $s->operator_id, 'factory_id' => $s->factory_id,
                'starts_on' => $this->startsOn($s, $open), 'priority' => $s->priority,
                'planned_start' => $s->planned_start ? substr((string) $s->planned_start, 0, 10) : null,
                'stage_due_date' => $s->deadline ? substr((string) $s->deadline, 0, 10) : null,
                'order_due_at' => $s->order->dates?->finish_date ? (string) $s->order->dates->finish_date : null,
                'helper_ids' => $s->helpers->pluck('id')->values(), 'helpers' => $s->helpers, 'team_version' => \App\Support\TaskTeam::version($s),
                'order_id' => $s->order_id, 'order_number' => $s->order->orderNumber?->number, 'operator_name' => $s->operator?->name,
                'due_date' => substr((string) ($s->deadline ?: $s->order->dates?->finish_date), 0, 10) ?: null,
                'deadline_source' => $s->deadline ? 'stage' : ($s->order->dates?->finish_date ? 'order' : null),
                'estimated_minutes' => $s->estimated_minutes, 'status' => $s->status,
                'name' => $s->order->name, 'title' => $this->title($s->order), 'workshop' => $s->factory->name, 'engineer' => $s->order->creator?->name,
                'blocked' => $s->is_blocked, 'can_edit' => !$s->awaiting_engineer_confirmation && $this->editable($request->user(), $s->order),
                'planned' => $future->contains(fn ($b) => (int) $b->factory_order_id === (int) $s->id && (int) $b->operator_id === (int) $s->operator_id),
            ])->values()]);
    }
    public function addShift(Request $request) {
        $this->manager($request);
        $data = $request->validate(['operator_id' => 'required|integer', 'dates' => 'required|array|min:1|max:31', 'dates.*' => 'required|date_format:Y-m-d|distinct',
            'start' => 'required|date_format:H:i', 'end' => 'required|date_format:H:i|after:start']);
        abort_unless(User::whereKey($data['operator_id'])->forRoles(MembershipAssignments::OPERATORS)->exists(), 422);
        DB::transaction(function () use ($data) {
            \App\Support\StageReservation::lockOperator($data['operator_id']);
            foreach ($data['dates'] as $date) {
                $start = $date.' '.$data['start'].':00'; $end = $date.' '.$data['end'].':00';
                abort_if(OperatorWorkInterval::where('operator_id', $data['operator_id'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->lockForUpdate()->first(), 409, 'Աշխատանքային միջակայքերը համընկնում են։');
                OperatorWorkInterval::create(['operator_id' => $data['operator_id'], 'starts_at' => $start, 'ends_at' => $end]);
            }
        });
        return response()->json(['saved' => true], 201);
    }
    public function deleteShift(Request $request, OperatorWorkInterval $interval) {
        $this->manager($request);
        DB::transaction(function () use ($interval) {
            \App\Support\StageReservation::lockOperator($interval->operator_id);
            abort_if(OperatorBooking::active()->where('operator_id', $interval->operator_id)->where('starts_at', '<', $interval->ends_at)->where('ends_at', '>', $interval->starts_at)->lockForUpdate()->first(), 409, 'Նախ տեղափոխեք այս ժամերի աշխատանքները։');
            $interval->delete();
        });
        return response()->json(['deleted' => true]);
    }
    public function book(Request $request) {
        $this->authorizeRead($request);
        $data = $request->validate(['factory_order_id' => 'required|integer', 'operator_id' => 'required|integer', 'assign_if_unassigned' => 'sometimes|boolean', 'allow_without_shift' => 'sometimes|boolean', 'team_version' => 'sometimes|string|size:64']);
        [$start, $end] = $this->times($request);
        abort_if($start < CarbonImmutable::now('Asia/Yerevan')->format('Y-m-d H:i:s'), 422, 'Ընտրեք ապագա ժամանակ։');
        $booking = DB::transaction(function () use ($request, $data, $start, $end) {
            $step = FactoryOrder::whereKey($data['factory_order_id'])->lockForUpdate()->firstOrFail();
            TaskRouting::authorize($request->user(), $step->order);
            if (isset($data['team_version'])) abort_unless(hash_equals(\App\Support\TaskTeam::version($step), $data['team_version']), 409, 'Աշխատանքի կազմը փոխվել է։ Թարմացրեք էջը։');
            if (!$step->operator_id && !empty($data['assign_if_unassigned'])) {
                $step = TaskRouting::assign($request->user(), $step, (int) $data['operator_id']);
                DB::afterCommit(fn () => \App\Support\TaskNotifier::operatorChanged($request->user(), $step->order, $step, null));
            }
            abort_unless($step->factory->is_workshop && (int) $step->operator_id === (int) $data['operator_id'], 409, 'Աշխատանքի կատարողը փոխվել է կամ նշանակված չէ։');
            abort_if($step->isWorkCompleted() || $step->awaiting_engineer_confirmation || in_array($step->status, ['canceled', 'cancelled'], true) || in_array($step->order->status, ['canceled', 'cancelled'], true), 422);
            return \App\Support\StageReservation::reserve($request->user(), $step, $start, $end, !empty($data['allow_without_shift']));
        }, 3);
        return response()->json(['booking' => $booking], 201);
    }
    // Puts an unassigned workshop stage into an operator's free days: assigns
    // the operator and sets the workshop deadline in one step.
    public function place(Request $request) {
        $this->authorizeRead($request);
        $data = $request->validate(['factory_order_id' => 'required|integer', 'operator_id' => 'required|integer',
            'deadline' => 'required|date_format:Y-m-d', 'starts_on' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:deadline']);
        $today = CarbonImmutable::now('Asia/Yerevan')->toDateString();
        abort_if($data['deadline'] < $today || ($data['starts_on'] ?? $today) < $today, 422, 'Ընտրեք այսօրվա կամ ապագա օր։');
        $step = FactoryOrder::whereKey($data['factory_order_id'])->firstOrFail();
        TaskRouting::authorize($request->user(), $step->order);
        [$step, $previousOperatorId] = DB::transaction(function () use ($request, $data, $step) {
            $order = \App\Models\Order::whereKey($step->order_id)->lockForUpdate()->firstOrFail();
            $locked = FactoryOrder::whereKey($step->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->factory?->is_workshop, 422);
            abort_if($locked->operator_id && (int) $locked->operator_id !== (int) $data['operator_id'], 409, 'Աշխատանքն արդեն ունի կատարող։ Թարմացրեք էջը։');
            $finish = substr((string) $order->dates?->finish_date, 0, 10);
            abort_if($finish && $data['deadline'] > $finish, 422, 'Արտադրամասի ժամկետը չի կարող լինել առաջադրանքի ընդհանուր ժամկետից հետո։');
            $previous = $locked->operator_id;
            $locked = TaskRouting::assign($request->user(), $locked, (int) $data['operator_id']);
            $before = $locked->deadline ? substr((string) $locked->deadline, 0, 10) : null;
            if (array_key_exists('starts_on', $data)) $locked->planned_start = $data['starts_on'];
            if ($before !== $data['deadline']) {
                $locked->deadline = $data['deadline'];
                OrderLog::create(['order_id' => $order->id, 'user_id' => $request->user()->id, 'action' => 'factory_order.deadline_changed',
                    'message' => sprintf('Արտադրամաս «%s»․ ժամկետ «%s» → «%s»', $locked->factory->name, $before ?? '—', $data['deadline']),
                    'meta' => ['factory_order_id' => $locked->id, 'from' => $before, 'to' => $data['deadline']]]);
            }
            $locked->save();
            return [$locked->fresh(['order', 'factory']), $previous];
        }, 3);
        if ((int) $previousOperatorId !== (int) $step->operator_id)
            \App\Support\TaskNotifier::operatorChanged($request->user(), $step->order, $step, $previousOperatorId);
        return response()->json(['placed' => true, 'factory_order_id' => $step->id, 'operator_id' => $step->operator_id, 'deadline' => $data['deadline']]);
    }
    public function cancel(Request $request, OperatorBooking $booking) {
        $this->authorizeRead($request); TaskRouting::authorize($request->user(), $booking->step->order);
        DB::transaction(function () use ($booking, $request) {
            $booking = OperatorBooking::findOrFail($booking->primary_booking_id ?: $booking->id);
            FactoryOrder::whereKey($booking->factory_order_id)->lockForUpdate()->firstOrFail();
            $ids = OperatorBooking::where(fn ($q) => $q->whereKey($booking->id)->orWhere('primary_booking_id', $booking->id))->pluck('operator_id')->sort()->unique();
            foreach ($ids as $id) \App\Support\StageReservation::lockOperator((int) $id);
            OrderLog::create(['order_id' => $booking->step->order_id, 'user_id' => $request->user()->id, 'action' => 'calendar.unbooked', 'message' => 'Պլանավորված միջակայքը հանվել է՝ '.$booking->starts_at.' — '.$booking->ends_at]);
            OperatorBooking::where('primary_booking_id', $booking->id)->delete();
            $booking->delete();
        });
        return response()->json(['deleted' => true]);
    }
}
