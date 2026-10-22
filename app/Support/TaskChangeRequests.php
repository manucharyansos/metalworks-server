<?php

namespace App\Support;

use App\Models\{FactoryOrder, Order, OrderLog, TaskChangeRequest, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rejecting or reopening a workshop job, or moving its deadline, needs the consent of the
 * engineer who created the task. Operators only ask; the engineer decides.
 */
final class TaskChangeRequests
{
    public const RESOLUTIONS = ['canceled', 'reassigned'];

    /**
     * Called inside the status-update transaction, which already holds the
     * order and step locks. The job keeps its current status until a decision.
     */
    public static function submit(User $actor, Order $order, FactoryOrder $step, string $type, array $data): TaskChangeRequest
    {
        if ($step->changeRequests()->pending()->exists()) {
            throw ValidationException::withMessages(['factory_order.status' => ['Այս աշխատանքի համար արդեն կա ինժեների պատասխանին սպասող հարցում։ Սպասեք պատասխանին կամ հետ վերցրեք այն։']]);
        }
        $request = TaskChangeRequest::create([
            'order_id' => $order->id, 'factory_order_id' => $step->id, 'requested_by' => $actor->id, 'type' => $type,
            'reason' => $type === TaskChangeRequest::REJECT ? $data['reason'] : null,
            'operator_note' => ($data['note'] ?? null) ? trim($data['note']) : null,
            'proposed_date' => $type === TaskChangeRequest::DEADLINE ? $data['date'] : null,
            'previous_deadline' => $step->deadline ?: $order->dates?->finish_date,
            'requested_status' => $type === TaskChangeRequest::REOPEN ? $data['requested_status'] : null,
            'status' => TaskChangeRequest::PENDING,
        ]);
        // Asking about a job takes it, like any other operator action.
        if (!$step->operator_id && $actor->factory_id) $step->update(['operator_id' => $actor->id]);
        OrderLog::create(['order_id' => $order->id, 'user_id' => $actor->id, 'action' => 'factory_order.request_submitted',
            'message' => sprintf('Արտադրամաս «%s»․ %s՝ սպասում է ինժեների համաձայնությանը։', $step->factory->name, self::label($request)),
            'meta' => ['factory_order_id' => $step->id, 'request_id' => $request->id, 'type' => $type,
                'reason' => $request->reason, 'proposed_date' => $request->proposed_date?->format('Y-m-d'), 'requested_status' => $request->requested_status]]);
        return $request;
    }

    /** The requester (or management) takes the request back while it is waiting. */
    public static function withdraw(User $actor, TaskChangeRequest $request): array
    {
        return DB::transaction(function () use ($actor, $request) {
            // Use the same order/step/request lock order as operator status updates.
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            $step = $order->factoryOrders()->whereKey($request->factory_order_id)->lockForUpdate()->firstOrFail();
            $locked = $step->changeRequests()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($actor->role?->name, ['admin', 'manager'], true) || (int) $locked->requested_by === (int) $actor->id, 403);
            if ($locked->status === TaskChangeRequest::WITHDRAWN) return [$locked, $order, $step, false];
            if ($locked->status !== TaskChangeRequest::PENDING) {
                throw ValidationException::withMessages(['request' => ['Հարցումն արդեն մշակված է։']]);
            }
            $locked->update(['status' => TaskChangeRequest::WITHDRAWN, 'decided_by' => $actor->id, 'decided_at' => now()]);
            OrderLog::create(['order_id' => $order->id, 'user_id' => $actor->id, 'action' => 'factory_order.request_withdrawn',
                'message' => sprintf('Արտադրամաս «%s»․ %s՝ հարցումը հետ է վերցվել։', $step->factory->name, self::label($locked)),
                'meta' => ['factory_order_id' => $step->id, 'request_id' => $locked->id]]);
            return [$locked, $order, $step, true];
        });
    }

    /**
     * Only the engineer who created the task decides. A repeated identical
     * decision is accepted without changing anything (safe double-click).
     *
     * @param array{note?: ?string, resolution?: ?string, operator_id?: ?int} $data
     */
    public static function decide(User $actor, TaskChangeRequest $request, string $decision, array $data = []): TaskChangeRequest
    {
        $outcome = DB::transaction(function () use ($actor, $request, $decision, $data) {
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role?->name === 'engineer' && (int) $order->creator_id === (int) $actor->id, 403);
            $step = $order->factoryOrders()->whereKey($request->factory_order_id)->lockForUpdate()->firstOrFail();
            $locked = $step->changeRequests()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $approve = $decision === 'approve';
            if ($locked->status !== TaskChangeRequest::PENDING) {
                if ($locked->status === ($approve ? TaskChangeRequest::APPROVED : TaskChangeRequest::DECLINED)) return [$locked, null, null, null];
                throw ValidationException::withMessages(['decision' => ['Հարցումն արդեն մշակված է։']]);
            }
            $reopen = $locked->type === TaskChangeRequest::REOPEN;
            if ($step->isWorkCompleted() || $step->awaiting_engineer_confirmation
                || ($reopen ? !in_array($step->status, ['canceled', 'cancelled'], true)
                    : (in_array($step->status, ['canceled', 'cancelled'], true) || in_array($order->status, ['canceled', 'cancelled'], true)))) {
                throw ValidationException::withMessages(['decision' => ['Աշխատանքն արդեն ավարտված կամ չեղարկված է։ Հարցումն այլևս կիրառելի չէ։']]);
            }
            $note = ($data['note'] ?? null) ? trim($data['note']) : null;
            $previousOperatorId = $step->operator_id;
            $resolution = null;

            if ($approve && $reopen) {
                if (!in_array($locked->requested_status, ['pending', 'accepted', 'confirmed'], true)) throw ValidationException::withMessages(['decision' => ['Վերաբացման կարգավիճակը վավեր չէ։']]);
                if ($step->is_blocked && in_array($locked->requested_status, ['accepted', 'confirmed'], true)) throw ValidationException::withMessages(['decision' => ['Նախորդ արտադրամասի աշխատանքը դեռ չի ավարտվել։']]);
                $step->update(['status' => $locked->requested_status, 'canceling' => '', 'cancel_date' => null,
                    'completed_at' => null, 'finish_date' => null, 'operator_finish_date' => null]);
                $order->status = 'pending'; $order->completed_at = null; $order->save();
                $resolution = 'reopened';
            } elseif ($approve && $locked->type === TaskChangeRequest::DEADLINE) {
                if ($locked->proposed_date->startOfDay()->lte(today())) {
                    throw ValidationException::withMessages(['proposed_date' => ['Առաջարկվող ժամկետն արդեն անցել է։ Կատարողը պետք է ուղարկի նոր հարցում։']]);
                }
                $date = $locked->proposed_date->format('Y-m-d');
                if ($step->deadline) $step->deadline = $date;
                else $order->dates()->updateOrCreate(['order_id' => $order->id], ['finish_date' => $date]);
                $step->fill(['cancel_date' => $date, 'canceling' => ''])->save();
                $resolution = 'deadline_changed';
            } elseif ($approve) {
                $resolution = $data['resolution'] ?? 'canceled';
                if (!in_array($resolution, self::RESOLUTIONS, true)) {
                    throw ValidationException::withMessages(['resolution' => ['Ընտրեք՝ մերժել աշխատանքը թե փոխանցել այլ կատարողի։']]);
                }
                if ($resolution === 'reassigned') {
                    $operatorId = (int) ($data['operator_id'] ?? 0);
                    if (!$operatorId || $operatorId === (int) $locked->requested_by) {
                        throw ValidationException::withMessages(['operator_id' => ['Ընտրեք մեկ այլ կատարողի։']]);
                    }
                    TaskRouting::assign($actor, $step, $operatorId, false);
                    $step = $step->fresh();
                    $step->update(['status' => 'pending', 'canceling' => '', 'cancel_date' => null]);
                } else {
                    $step->update(['status' => 'canceled', 'canceling' => (string) $locked->reason]);
                }
            }

            $locked->update(['status' => $approve ? TaskChangeRequest::APPROVED : TaskChangeRequest::DECLINED,
                'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'resolution' => $resolution]);
            OrderLog::create(['order_id' => $order->id, 'user_id' => $actor->id,
                'action' => $approve ? 'factory_order.request_approved' : 'factory_order.request_declined',
                'message' => sprintf('Արտադրամաս «%s»․ %s՝ %s։', $step->factory->name, self::label($locked), $approve ? 'ինժեները համաձայնել է' : 'ինժեները մերժել է'),
                'meta' => ['factory_order_id' => $step->id, 'request_id' => $locked->id, 'resolution' => $resolution, 'note' => $note]]);
            $order->updateStatusIfAllFactoriesCompleted();
            ProductionRoute::refreshReadiness($order, $actor);
            return [$locked, $order, $step, $previousOperatorId];
        });

        [$decided, $order, $step, $previousOperatorId] = $outcome;
        if ($order) {
            TaskNotifier::requestDecided($actor, $order, $step, $decided);
            if ($decided->resolution === 'reassigned') TaskNotifier::operatorChanged($actor, $order, $step, $previousOperatorId);
        }
        return $decided;
    }

    /** Close any waiting request when the job moves on without it (new status, new operator, management override). */
    public static function supersede(FactoryOrder $step, ?User $actor = null): int
    {
        return $step->changeRequests()->pending()->update(['status' => TaskChangeRequest::WITHDRAWN, 'decided_by' => $actor?->id, 'decided_at' => now()]);
    }

    private static function label(TaskChangeRequest $request): string
    {
        return match ($request->type) { TaskChangeRequest::REJECT => 'մերժման հարցում', TaskChangeRequest::REOPEN => 'աշխատանքը վերաբացելու հարցում', default => 'ժամկետի փոփոխության հարցում' };
    }
}
