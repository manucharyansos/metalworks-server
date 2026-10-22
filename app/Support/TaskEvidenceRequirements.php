<?php

namespace App\Support;

use App\Models\{Order, OrderLog, User};
use Illuminate\Support\Facades\DB;

/** Change proof requirements without rebuilding routing, files or assignments. */
final class TaskEvidenceRequirements
{
    public static function update(User $actor, Order $order, array $data, ?int $stepId = null): array
    {
        return DB::transaction(function () use ($actor, $order, $data, $stepId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            TaskRouting::authorize($actor, $order);
            abort_if(in_array($order->status, ['canceled', 'cancelled'], true), 422, 'Չեղարկված առաջադրանքի հավաստման պահանջները փոխել հնարավոր չէ։');
            $settings = TaskWorkflow::settings($data);
            $steps = $order->factoryOrders()->orderBy('id')->lockForUpdate()->get();
            $target = $stepId ? $steps->firstWhere('id', $stepId) : null;
            if ($stepId) {
                abort_unless($target, 404);
                abort_if($target->engineer_confirmation_at || in_array($target->status, ['canceled', 'cancelled'], true), 422, 'Հաստատված կամ չեղարկված աշխատանքի կարգավորումները պահպանվում են։');
                $settings += ['deadline' => $data['deadline'] ?? null, 'priority' => $data['priority']];
            }
            $previous = TaskWorkflow::settings(($target ?: $order)->toArray());
            if ($target) $previous += ['deadline' => $target->deadline, 'priority' => $target->priority];
            // A retry after a lost response is harmless; a stale editor cannot
            // overwrite a different requirement saved in another session.
            if ($previous === $settings) return [$order, []];
            $expected = [
                'confirmation_required' => (bool) $data['previous_confirmation_required'],
                'confirmation_method' => $data['previous_confirmation_required'] ? $data['previous_confirmation_method'] : null,
            ];
            if ($target) $expected += ['deadline' => $data['previous_deadline'] ?? null, 'priority' => $data['previous_priority']];
            abort_unless($previous === $expected, 409, 'Աշխատանքի կարգավորումներն արդեն փոխվել են։ Թարմացրեք առաջադրանքը և կրկին փորձեք։');

            $changes = [];
            $methods = TaskWorkflow::evidenceMethods($settings['confirmation_method']);
            // Use the same order → step lock order as completion and routing.
            foreach ($steps as $step) {
                if ($target && $step->id !== $target->id) continue;
                // An engineer's approval remains a permanent record. Canceled
                // workshop jobs are also final and must not be reopened here.
                if ($step->engineer_confirmation_at || in_array($step->status, ['canceled', 'cancelled'], true)) continue;
                $from = TaskWorkflow::settings($step->toArray());
                if ($target) $from += ['deadline' => $step->deadline, 'priority' => $step->priority];
                if ($from === $settings) continue;
                $finished = in_array($step->status, ['finished', 'completed', 'done'], true);
                $missing = array_values(array_filter($methods, fn ($method) => $method === 'photo'
                    ? !$step->evidence_photo_path : !trim((string) $step->evidence_text)));
                $needsSupplement = $finished && $settings['confirmation_required'] && ($missing || !$step->operator_finish_date);
                $history = ['factory_order_id' => $step->id, 'factory_id' => $step->factory_id,
                    'from' => $from, 'to' => $settings, 'needs_supplement' => $needsSupplement,
                    'previous_status' => $step->status, 'previous_operator_finish_date' => $step->operator_finish_date,
                    'previous_completed_at' => $step->completed_at,
                    'previous_evidence_text' => $step->evidence_text, 'previous_evidence_photo_path' => $step->evidence_photo_path];
                $step->fill($settings);
                if ($needsSupplement) {
                    $labels = array_map(fn ($method) => $method === 'photo' ? 'նկարը' : 'տեքստը', $missing ?: $methods);
                    $step->fill(['status' => 'confirmed', 'operator_finish_date' => null, 'finish_date' => null,
                        'completed_at' => null, 'returned_at' => now(),
                        'return_note' => 'Հավաստման պահանջները փոխվել են։ Լրացրեք աշխատանքի '.implode(' և ', $labels).' և կրկին ուղարկեք ավարտը։']);
                } elseif ($finished) {
                    $step->completed_at = $settings['confirmation_required'] ? null : ($step->completed_at ?: now());
                }
                $step->save();
                OrderLog::create(['order_id' => $order->id, 'user_id' => $actor->id,
                    'action' => 'factory_order.evidence_requirements_changed',
                    'message' => 'Փոխվել են արտադրամասի աշխատանքի հավաստման պահանջները։', 'meta' => $history]);
                $changes[] = ['step' => $step, 'from' => $from, 'needs_supplement' => $needsSupplement];
            }
            if (!$target) {
                $order->fill($settings)->save();
                OrderLog::create(['order_id' => $order->id, 'user_id' => $actor->id,
                'action' => 'order.evidence_requirements_changed',
                'message' => 'Փոխվել են առաջադրանքի հավաստման պահանջները։',
                'meta' => ['from' => $previous, 'to' => $settings, 'factory_order_ids' => array_map(fn ($change) => $change['step']->id, $changes)]]);
            }
            if ($steps->contains(fn ($step) => !in_array($step->status, ['canceled', 'cancelled'], true) && !$step->isWorkCompleted())) {
                $order->status = 'pending';
                $order->completed_at = null;
                $order->save();
            }
            $order->updateStatusIfAllFactoriesCompleted();
            ProductionRoute::refreshReadiness($order, $actor);
            return [$order, $changes];
        });
    }
}
