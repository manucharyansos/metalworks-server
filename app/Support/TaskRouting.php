<?php

namespace App\Support;

use App\Models\{Factory, FactoryOrder, Order, OrderLog, PmpFiles, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskRouting
{
    public static function authorize(User $user, Order $order): void
    {
        abort_unless(in_array($user->role?->name, ['admin', 'manager'], true)
            || ($user->role?->name === 'engineer' && (int) $order->creator_id === (int) $user->id && $user->hasPermission('orders.update')), 403);
    }

    public static function order(Order $order): Order
    {
        return $order->fresh()->load('orderNumber', 'prefixCode', 'dates', 'factoryOrders.factory',
            'factoryOrders.operator:id,name', 'factoryOrders.files', 'factoryOrders.dependsOn', 'factoryOrders.pendingRequest',
            'selectedFiles.pmpFile.factory', 'client.user', 'user:id,name,email,phone', 'creator:id,name', 'logs.user:id,name');
    }

    public static function operator(?int $id, int $factoryId): ?User
    {
        if (!$id) return null;
        $operator = User::whereKey($id)->assignedToFactory($factoryId)->forRoles(MembershipAssignments::OPERATORS)->first();
        if (!$operator) throw ValidationException::withMessages(['operator_id' => ['Ընտրված աշխատակիցը չունի այս ընկերության արտադրամասում կատարողի հասանելիություն։']]);
        return $operator;
    }

    public static function assign(User $actor, FactoryOrder $step, ?int $operatorId, bool $supersedeRequests = true): FactoryOrder
    {
        return DB::transaction(function () use ($actor, $step, $operatorId, $supersedeRequests) {
            $order = Order::whereKey($step->order_id)->lockForUpdate()->firstOrFail();
            self::authorize($actor, $order);
            $locked = $order->factoryOrders()->whereKey($step->id)->lockForUpdate()->firstOrFail();
            if ($locked->isWorkCompleted() || $locked->awaiting_engineer_confirmation
                || in_array($locked->status, ['canceled', 'cancelled'], true)
                || in_array($order->status, ['canceled', 'cancelled'], true)) {
                throw ValidationException::withMessages(['operator_id' => ['Ավարտված կամ չեղարկված աշխատանքի կատարողին փոխել հնարավոր չէ։']]);
            }
            $operator = self::operator($operatorId, (int) $locked->factory_id);
            if ((int) $locked->operator_id === (int) $operator?->id) return $locked;
            $previous = $locked->operator;
            $locked->update(['operator_id' => $operator?->id]);
            // A request made by the previous operator no longer applies.
            if ($supersedeRequests) TaskChangeRequests::supersede($locked, $actor);
            OrderLog::create(['order_id' => $order->id, 'user_id' => $actor->id, 'action' => 'factory_order.operator_changed',
                'message' => sprintf('Արտադրամաս «%s»․ կատարող «%s» → «%s»', $locked->factory->name, $previous?->name ?? 'Չնշանակված', $operator?->name ?? 'Չնշանակված'),
                'meta' => ['factory_order_id' => $locked->id, 'factory_id' => $locked->factory_id,
                    'from_operator_id' => $previous?->id, 'to_operator_id' => $operator?->id]]);
            return $locked;
        });
    }

    public static function addWork(User $actor, Order $order, array $data): FactoryOrder
    {
        return DB::transaction(function () use ($actor, $order, $data) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            self::authorize($actor, $order);
            if (in_array($order->status, ['canceled', 'cancelled'], true)) throw ValidationException::withMessages(['order' => ['Չեղարկված առաջադրանքին աշխատանք ավելացնել հնարավոր չէ։']]);
            $factory = Factory::whereKey($data['factory_id'])->sharedLock()->firstOrFail();
            if (!$factory->is_workshop || !$factory->is_active || $factory->is_reference) throw ValidationException::withMessages(['factory_id' => ['INFO/PDF-ը տեղեկատու բաժիններ են։ Ընտրեք արտադրամաս։']]);
            $steps = $order->factoryOrders()->with('files.factory')->get();
            $step = $steps->firstWhere('factory_id', $factory->id);
            if ($step && ($step->isWorkCompleted() || !in_array($step->status, [null, '', 'pending', 'waiting'], true))) {
                throw ValidationException::withMessages(['factory_id' => ['Արդեն սկսված արտադրամասի ֆայլերը փոխել հնարավոր չէ։ Կարող եք ավելացնել այլ արտադրամասի աշխատանք։']]);
            }
            $availableIds = $order->selectedFiles()->pluck('pmp_file_id')->merge($steps->flatMap(fn ($s) => $s->files->pluck('id')))->unique();
            $ids = array_column($data['files'], 'id');
            if (array_diff($ids, $availableIds->all())) throw ValidationException::withMessages(['files' => ['Կցեք միայն այս առաջադրանքի ֆայլերը։']]);
            $files = PmpFiles::with('factory')->whereIn('id', $ids)->get()->keyBy('id');
            if ($files->count() !== count($ids) || !$files->contains(fn ($file) => !$file->is_reference_file)) {
                throw ValidationException::withMessages(['files' => ['Ընտրեք առնվազն մեկ աշխատանքային ֆայլ։']]);
            }
            if ($step && $step->files->pluck('id')->intersect($ids)->isNotEmpty()) throw ValidationException::withMessages(['files' => ['Ֆայլն արդեն կցված է այս արտադրամասի աշխատանքին։']]);
            $dependencyField = array_key_exists('predecessor_ids', $data) ? 'predecessor_ids' : 'depends_on_id';
            $predecessorIds = array_key_exists('predecessor_ids', $data) ? array_map('intval', $data['predecessor_ids'])
                : (array_key_exists('depends_on_id', $data) ? ($data['depends_on_id'] ? [(int) $data['depends_on_id']] : []) : ($step?->dependencyIds() ?? []));
            foreach ($predecessorIds as $id) {
                $source = $steps->firstWhere('id', $id);
                if (!$source || in_array($source->status, ['canceled', 'cancelled'], true)) throw ValidationException::withMessages([$dependencyField => ['Ընտրեք այս առաջադրանքի գործող կամ ավարտված արտադրամասի աշխատանքը։']]);
            }
            $targetId = $step?->id ?? 0;
            $graph = $steps->mapWithKeys(fn ($existing) => [$existing->id => $existing->dependencyIds()])->all();
            $graph[$targetId] = $predecessorIds;
            ProductionRoute::validateGraph($graph, $dependencyField);
            $operatorId = array_key_exists('operator_id', $data) ? $data['operator_id'] : $step?->operator_id;
            $operator = self::operator($operatorId, (int) $factory->id);
            $step ??= new FactoryOrder(['order_id' => $order->id, 'factory_id' => $factory->id, 'status' => 'pending'] + TaskWorkflow::settings($order->toArray()));
            foreach (['deadline', 'priority'] as $field) if (array_key_exists($field, $data)) $step->$field = $data[$field];
            if (array_key_exists('confirmation_required', $data)) $step->fill(TaskWorkflow::settings($data));
            $previousOperatorId = $step->operator_id;
            $step->fill(['operator_id' => $operator?->id, 'depends_on_id' => $predecessorIds[0] ?? null, 'predecessor_ids' => $predecessorIds,
                'route_position' => $step->exists ? $step->route_position : ((int) $steps->max('route_position') + 1)])->save();
            $shares = collect($order->reference_file_visibility ?? [])->keyBy('file_id');
            foreach ($data['files'] as $item) {
                $file = $files->get($item['id']);
                $revisionId = $order->selectedFiles()->where('pmp_file_id', $file->id)->value('task_file_revision_id');
                $step->files()->attach($file->id, ['quantity' => $item['quantity'], 'material_type' => $file->material_type, 'thickness' => $file->thickness,
                    'dimensions' => $file->dimensions, 'inspection_required' => (bool) $file->inspection_required, 'task_file_revision_id' => $revisionId]);
                if ($file->is_reference_file) {
                    $row = $shares->get($file->id, ['file_id' => $file->id, 'factory_ids' => []]);
                    $row['factory_ids'] = array_values(array_unique([...$row['factory_ids'], $factory->id]));
                    $shares->put($file->id, $row);
                }
            }
            $wasCompleted = $order->status === 'completed';
            $order->status = 'pending';
            $order->completed_at = null;
            $order->reference_file_visibility = $shares->values()->all();
            $order->save();
            OrderLog::create(['order_id' => $order->id, 'user_id' => $actor->id, 'action' => 'factory_order.work_added',
                'message' => sprintf('Արտադրամաս «%s»․ ավելացվել է %d ֆայլով աշխատանք։', $factory->name, count($ids)),
                'meta' => ['factory_order_id' => $step->id, 'factory_id' => $factory->id, 'file_ids' => $ids,
                    'depends_on_id' => $predecessorIds[0] ?? null, 'predecessor_ids' => $predecessorIds, 'from_operator_id' => $previousOperatorId,
                    'operator_id' => $operator?->id, 'reopened_task' => $wasCompleted]]);
            ProductionRoute::refreshReadiness($order, $actor);
            return $step;
        });
    }

    public static function workload(): array
    {
        $open = "(factory_orders.completed_at IS NULL AND (factory_orders.status IS NULL OR factory_orders.status NOT IN ('finished', 'completed', 'done', 'canceled', 'cancelled')))";
        $stats = FactoryOrder::forWorkshops()->join('orders as task', 'task.id', '=', 'factory_orders.order_id')
            ->leftJoin('dates as deadline', 'deadline.order_id', '=', 'task.id')
            ->whereNotIn('task.status', ['canceled', 'cancelled'])
            ->select('factory_orders.factory_id', 'factory_orders.operator_id')
            ->selectRaw("SUM(CASE WHEN {$open} THEN 1 ELSE 0 END) as active_tasks")
            ->selectRaw("SUM(CASE WHEN {$open} AND COALESCE(factory_orders.deadline, deadline.finish_date) < ? THEN 1 ELSE 0 END) as overdue_tasks", [today()->toDateString()])
            ->selectRaw("SUM(CASE WHEN {$open} AND DATE(COALESCE(factory_orders.deadline, deadline.finish_date)) = ? THEN 1 ELSE 0 END) as due_today", [today()->toDateString()])
            ->selectRaw("SUM(CASE WHEN {$open} AND factory_orders.priority = 'urgent' THEN 1 ELSE 0 END) as urgent_tasks")
            ->selectRaw("SUM(CASE WHEN factory_orders.confirmation_required = 1 AND factory_orders.engineer_confirmation_at IS NULL AND factory_orders.status IN ('finished', 'completed', 'done') THEN 1 ELSE 0 END) as awaiting_review")
            ->groupBy('factory_orders.factory_id', 'factory_orders.operator_id')->get();
        $factories = Factory::activeWorkshops()->with(['operators' => fn ($q) => $q->forRoles(MembershipAssignments::OPERATORS)->select('users.id', 'users.name')])
            ->orderBy('name')->get()->reject(fn ($factory) => $factory->is_reference)->map(function ($factory) use ($stats) {
                $rows = $stats->where('factory_id', $factory->id);
                return ['id' => $factory->id, 'name' => $factory->name, 'value' => $factory->value,
                    'unassigned_tasks' => (int) ($rows->firstWhere('operator_id', null)?->active_tasks ?? 0),
                    'operators' => $factory->operators->map(function ($operator) use ($rows) {
                        $row = $rows->firstWhere('operator_id', $operator->id);
                        return ['id' => $operator->id, 'name' => $operator->name, 'active_tasks' => (int) ($row?->active_tasks ?? 0),
                            'overdue_tasks' => (int) ($row?->overdue_tasks ?? 0), 'due_today' => (int) ($row?->due_today ?? 0), 'urgent_tasks' => (int) ($row?->urgent_tasks ?? 0), 'awaiting_review' => (int) ($row?->awaiting_review ?? 0)];
                    })->sortBy('active_tasks')->values()->all()];
            })->values()->all();
        return ['company_id' => app(CompanyContext::class)->id(), 'generated_at' => now()->toIso8601String(), 'factories' => $factories];
    }
}
