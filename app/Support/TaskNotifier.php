<?php

namespace App\Support;

use App\Models\{FactoryOrder, FactoryOrderStatus, Order, TaskChangeRequest, TaskNotification, User};
use Illuminate\Support\Facades\Log;

/**
 * Stores in-app notifications for the people involved in a task cycle.
 *
 * `message` is a readable Armenian fallback; the client renders localized text
 * from `type` and `data`. A failed notification must never break the action
 * that caused it, so every write is guarded.
 */
final class TaskNotifier
{
    /** An operator (or manager) changed a workshop job: tell the creating engineer. */
    public static function statusChanged(User $actor, Order $order, FactoryOrder $step, ?string $from, ?string $to): void
    {
        if (($from ?? '') === ($to ?? '')) return;
        $review = $step->awaiting_engineer_confirmation;
        $label = self::ref($order);
        $message = $review
            ? sprintf('«%s» արտադրամաս․ %s ավարտել է աշխատանքը և սպասում է ձեր հաստատմանը (%s)։', self::factory($step), $actor->name, $label)
            : sprintf('«%s» արտադրամաս․ %s փոխեց կարգավիճակը՝ %s → %s (%s)։', self::factory($step), $actor->name, self::statusLabel($from), self::statusLabel($to), $label);
        self::send($order->creator_id, $actor, $review ? 'step.finished_review' : 'step.status_changed', $order, $step, $message, [
            'from_status' => $from, 'to_status' => $to,
            'from_label' => self::statusLabel($from), 'to_label' => self::statusLabel($to),
        ]);
    }

    public static function requestSubmitted(User $actor, Order $order, FactoryOrder $step, TaskChangeRequest $request): void
    {
        $what = $request->type === TaskChangeRequest::REJECT
            ? 'խնդրում է մերժել աշխատանքը'
            : 'խնդրում է փոխել ժամկետը՝ '.optional($request->proposed_date)->format('d.m.Y');
        self::send($order->creator_id, $actor, 'request.submitted', $order, $step,
            sprintf('«%s» արտադրամաս․ %s %s (%s)։ Սպասում է ձեր համաձայնությանը։', self::factory($step), $actor->name, $what, self::ref($order)),
            self::requestData($request));
    }

    public static function requestWithdrawn(User $actor, Order $order, FactoryOrder $step, TaskChangeRequest $request): void
    {
        self::send($order->creator_id, $actor, 'request.withdrawn', $order, $step,
            sprintf('«%s» արտադրամաս․ %s հետ է վերցրել իր հարցումը (%s)։', self::factory($step), $actor->name, self::ref($order)),
            self::requestData($request));
    }

    /** The engineer answered a request: tell the operator who sent it. */
    public static function requestDecided(User $actor, Order $order, FactoryOrder $step, TaskChangeRequest $request): void
    {
        $approved = $request->status === TaskChangeRequest::APPROVED;
        $message = sprintf($approved ? 'Ինժեները համաձայնել է ձեր հարցմանը (%s)։' : 'Ինժեները չի համաձայնել ձեր հարցմանը (%s)։', self::ref($order));
        if ($request->decision_note) $message .= ' '.$request->decision_note;
        self::send($request->requested_by, $actor, $approved ? 'request.approved' : 'request.declined', $order, $step, $message, self::requestData($request));
    }

    public static function finishConfirmed(User $actor, Order $order, FactoryOrder $step): void
    {
        self::send($step->operator_id, $actor, 'step.finish_confirmed', $order, $step,
            sprintf('Ինժեները հաստատել է ձեր աշխատանքի ավարտը (%s)։', self::ref($order)));
    }

    public static function finishReturned(User $actor, Order $order, FactoryOrder $step, string $note): void
    {
        self::send($step->operator_id, $actor, 'step.finish_returned', $order, $step,
            sprintf('Ինժեները վերադարձրել է աշխատանքը վերանայման (%s)՝ %s', self::ref($order), $note), ['note' => $note]);
    }

    /** A job was given to a new operator (and taken from the previous one). */
    public static function operatorChanged(User $actor, Order $order, FactoryOrder $step, ?int $previousOperatorId): void
    {
        if ($step->operator_id && (int) $step->operator_id !== (int) $previousOperatorId) {
            self::send($step->operator_id, $actor, 'step.assigned', $order, $step,
                sprintf('Ձեզ նշանակվել է աշխատանք (%s)՝ «%s» արտադրամաս։', self::ref($order), self::factory($step)));
        }
        if ($previousOperatorId && (int) $previousOperatorId !== (int) $step->operator_id) {
            self::send($previousOperatorId, $actor, 'step.unassigned', $order, $step,
                sprintf('Աշխատանքը (%s) փոխանցվել է այլ կատարողի։', self::ref($order)));
        }
    }

    /** A new task was created with operators already chosen. */
    public static function stepsAssigned(User $actor, Order $order): void
    {
        foreach ($order->factoryOrders()->whereNotNull('operator_id')->get() as $step) self::operatorChanged($actor, $order, $step, null);
    }

    private static function send(?int $recipientId, ?User $actor, string $type, Order $order, ?FactoryOrder $step, string $message, array $data = []): void
    {
        if (!$recipientId || ($actor && (int) $actor->id === (int) $recipientId)) return;
        try {
            TaskNotification::create([
                'user_id' => $recipientId, 'actor_id' => $actor?->id, 'type' => $type,
                'order_id' => $order->id, 'factory_order_id' => $step?->id,
                'message' => mb_substr($message, 0, 1000),
                'data' => $data + array_filter([
                    'order_number' => $order->orderNumber?->number, 'order_name' => $order->name,
                    'factory' => $step ? self::factory($step) : null, 'actor' => $actor?->name,
                ], fn ($value) => $value !== null && $value !== ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Task notification was not stored', ['type' => $type, 'order_id' => $order->id, 'exception' => $e]);
        }
    }

    private static function requestData(TaskChangeRequest $request): array
    {
        return array_filter([
            'request_id' => $request->id, 'request_type' => $request->type, 'reason' => $request->reason,
            'operator_note' => $request->operator_note, 'proposed_date' => $request->proposed_date?->format('Y-m-d'),
            'decision_note' => $request->decision_note, 'resolution' => $request->resolution,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private static function ref(Order $order): string
    {
        return $order->orderNumber?->number ?: ($order->name ?: '#'.$order->id);
    }

    private static function factory(FactoryOrder $step): string
    {
        return (string) ($step->factory?->name ?? '');
    }

    private static function statusLabel(?string $status): string
    {
        if ($status === null || in_array($status, ['', 'pending', 'waiting'], true)) return 'Սպասում';
        return FactoryOrderStatus::where('value', $status)->value('status_label') ?: $status;
    }
}
