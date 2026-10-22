<?php

namespace App\Support;

use App\Models\{FactoryOrder, Order, OrderLog, User};
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Changes run under the task row lock, shared with completion and assignment. */
final class ProductionRoute
{
    public static function rules(string $prefix = 'production_route'): array
    {
        return [
            $prefix => 'sometimes|array', $prefix.'.mode' => 'required_with:'.$prefix.'|in:parallel,sequential,custom',
            $prefix.'.stages' => 'required_with:'.$prefix.'|array|min:1|max:100',
            $prefix.'.stages.*.factory_id' => 'required|integer|distinct',
            $prefix.'.stages.*.predecessor_factory_ids' => 'sometimes|array|max:100',
            $prefix.'.stages.*.predecessor_factory_ids.*' => 'required|integer',
            $prefix.'.stages.*.deadline' => 'present|nullable|date_format:Y-m-d',
        ];
    }

    public static function snapshot(Collection $steps): array
    {
        $byId = $steps->keyBy('id');
        return $steps->sortBy(fn ($step) => sprintf('%010d-%010d', $step->route_position, $step->id))->map(function ($step) use ($byId) {
            $dependencies = array_map(fn ($id) => (int) ($byId->get($id)?->factory_id ?? -$id), $step->dependencyIds());
            sort($dependencies);
            return ['factory_id' => (int) $step->factory_id, 'predecessor_factory_ids' => $dependencies,
                'deadline' => $step->deadline ? substr((string) $step->deadline, 0, 10) : null];
        })->values()->all();
    }

    public static function apply(Order $order, array $plan, ?User $actor = null, ?array $expected = null): void
    {
        $steps = $order->factoryOrders()->forWorkshops()->with('factory')->lockForUpdate()->get();
        $before = self::snapshot($steps);
        if ($expected !== null) abort_unless($before === $expected, 409, 'Երթուղին կամ արտադրամասերի ժամկետներն արդեն փոխվել են։ Թարմացրեք առաջադրանքը։');
        abort_if(in_array($order->status, ['canceled', 'cancelled'], true), 422, 'Չեղարկված առաջադրանքի երթուղին փոխել հնարավոր չէ։');
        $stages = $plan['stages'];
        $ids = array_map(fn ($row) => (int) $row['factory_id'], $stages);
        if (count(array_unique($ids)) !== count($ids) || array_diff($ids, $steps->pluck('factory_id')->all()) || array_diff($steps->pluck('factory_id')->all(), $ids)) {
            self::invalid('Երթուղում նշեք առաջադրանքի բոլոր արտադրամասերը՝ յուրաքանչյուրն ընդամենը մեկ անգամ։');
        }
        $byFactory = $steps->keyBy('factory_id'); $graph = []; $previous = null; $wasBlocked = [];
        foreach ($steps as $step) if ($step->is_blocked) $wasBlocked[] = (int) $step->id;
        foreach ($stages as $position => $row) {
            $factoryId = (int) $row['factory_id'];
            $dependencies = match ($plan['mode']) {
                'parallel' => [], 'sequential' => $previous ? [$previous] : [],
                default => array_values(array_unique(array_map('intval', $row['predecessor_factory_ids'] ?? []))),
            };
            if (in_array($factoryId, $dependencies, true) || array_diff($dependencies, $ids)) self::invalid('Ընտրեք միայն այս առաջադրանքի այլ արտադրամասերը։');
            $step = $byFactory->get($factoryId);
            $dependencyIds = array_map(fn ($id) => (int) $byFactory->get($id)->id, $dependencies);
            $old = $step->dependencyIds(); sort($old); $sorted = $dependencyIds; sort($sorted);
            if ($old !== $sorted && ($step->isWorkCompleted() || $step->awaiting_engineer_confirmation || !in_array($step->status, [null, '', 'pending', 'waiting'], true))) self::invalid('Արդեն սկսված կամ ավարտված փուլի նախորդ փուլերը փոխել հնարավոր չէ։');
            if ($old !== $sorted && collect($dependencies)->contains(fn ($id) => in_array($byFactory->get($id)->status, ['canceled', 'cancelled'], true))) self::invalid('Չեղարկված փուլը չի կարող նոր նախորդ փուլ լինել։');
            $deadline = $row['deadline'] ?? null;
            if (($step->isWorkCompleted() || $step->awaiting_engineer_confirmation || in_array($step->status, ['canceled', 'cancelled'], true)) && $deadline !== ($step->deadline ?: null)) self::invalid('Ավարտված կամ հաստատմանը սպասող փուլի ժամկետը պահպանվում է։');
            $graph[$factoryId] = $dependencies; $previous = $factoryId;
            $step->fill(['predecessor_ids' => $dependencyIds, 'depends_on_id' => $dependencyIds[0] ?? null, 'route_position' => $position, 'deadline' => $deadline]);
        }
        self::validateGraph($graph);
        foreach ($steps as $step) $step->save();
        $after = self::snapshot($steps);
        if ($actor && $before !== $after) {
            OrderLog::create(['order_id' => $order->id, 'user_id' => $actor->id, 'action' => 'order.production_route_changed',
                'message' => 'Փոխվել է արտադրական երթուղին։', 'meta' => ['from' => $before, 'to' => $after]]);
            foreach ($steps as $step) {
                $old = collect($before)->firstWhere('factory_id', (int) $step->factory_id);
                $new = collect($after)->firstWhere('factory_id', (int) $step->factory_id);
                if ($old !== $new) TaskNotifier::routeChanged($actor, $order, $step);
            }
        }
        self::refreshReadiness($order, $actor, $wasBlocked);
    }

    public static function validateGraph(array $graph, string $field = 'production_route'): void
    {
        $visited = []; $active = [];
        $visit = function ($id) use (&$visit, &$visited, &$active, $graph, $field) {
            if (isset($active[$id])) self::invalid('Արտադրամասերի հերթականությունը չի կարող շրջան կազմել։', $field);
            if (isset($visited[$id])) return;
            $active[$id] = true;
            foreach ($graph[$id] ?? [] as $dependency) {
                if (!array_key_exists($dependency, $graph)) self::invalid('Նախորդ փուլը չի պատկանում այս առաջադրանքին։', $field);
                $visit($dependency);
            }
            unset($active[$id]); $visited[$id] = true;
        };
        foreach (array_keys($graph) as $id) $visit($id);
    }

    public static function refreshReadiness(Order $order, ?User $actor, array $previouslyBlocked = []): void
    {
        if (in_array($order->status, ['canceled', 'cancelled'], true)) return;
        foreach ($order->factoryOrders()->forWorkshops()->lockForUpdate()->get() as $step) {
            if ($step->is_blocked || in_array($step->status, ['canceled', 'cancelled'], true)) {
                if ($step->ready_at) $step->update(['ready_at' => null]);
            } elseif (!$step->ready_at && !$step->isWorkCompleted() && !$step->awaiting_engineer_confirmation) {
                $step->update(['ready_at' => now()]);
                if ($step->dependencyIds() || in_array((int) $step->id, $previouslyBlocked, true)) TaskNotifier::stepReady($actor, $order, $step);
            }
        }
    }

    private static function invalid(string $message, string $field = 'production_route'): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
