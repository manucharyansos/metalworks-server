<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\{FactoryOrder, Order};
use App\Support\{ProductionRoute, TaskEvidenceRequirements, TaskNotifier, TaskRouting, TaskWorkflow};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskRoutingController extends Controller
{
    public function productionRoute(Request $request, Order $order)
    {
        TaskRouting::authorize($request->user(), $order);
        $data = $request->validate(['production_route' => 'required|array', 'previous_route' => 'required|array',
            'previous_route.*.factory_id' => 'required|integer',
            'previous_route.*.predecessor_factory_ids' => 'present|array',
            'previous_route.*.predecessor_factory_ids.*' => 'integer',
            'previous_route.*.deadline' => 'present|nullable|date_format:Y-m-d',
        ] + ProductionRoute::rules());
        DB::transaction(function () use ($request, $order, $data) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            TaskRouting::authorize($request->user(), $locked);
            ProductionRoute::apply($locked, $data['production_route'], $request->user(), $data['previous_route']);
        });
        return response()->json(['order' => TaskRouting::order($order), 'workload' => TaskRouting::workload()]);
    }

    public function workshopSettings(Request $request, Order $order, FactoryOrder $factoryOrder)
    {
        TaskRouting::authorize($request->user(), $order);
        abort_unless((int) $factoryOrder->order_id === (int) $order->id, 404);
        $data = $request->validate([
            'confirmation_required' => 'required|boolean',
            'confirmation_method' => TaskWorkflow::rules()['confirmation_method'],
            'previous_confirmation_required' => 'required|boolean',
            'previous_confirmation_method' => 'nullable|required_if:previous_confirmation_required,true|in:photo,text,photo_text',
            'deadline' => 'present|nullable|date_format:Y-m-d', 'priority' => 'required|in:normal,high,urgent',
            'previous_deadline' => 'present|nullable|date_format:Y-m-d', 'previous_priority' => 'required|in:normal,high,urgent',
        ]);
        [$order, $changes] = TaskEvidenceRequirements::update($request->user(), $order, $data, $factoryOrder->id);
        foreach ($changes as $change) TaskNotifier::evidenceRequirementsChanged($request->user(), $order,
            $change['step'], $change['from'], $change['needs_supplement']);
        return response()->json(['order' => TaskRouting::order($order), 'workload' => TaskRouting::workload()]);
    }

    public function evidenceRequirements(Request $request, Order $order)
    {
        TaskRouting::authorize($request->user(), $order);
        $data = $request->validate([
            'confirmation_required' => 'required|boolean',
            'confirmation_method' => TaskWorkflow::rules()['confirmation_method'],
            'previous_confirmation_required' => 'required|boolean',
            'previous_confirmation_method' => 'nullable|required_if:previous_confirmation_required,true|in:photo,text,photo_text',
        ]);
        [$order, $changes] = TaskEvidenceRequirements::update($request->user(), $order, $data);
        foreach ($changes as $change) TaskNotifier::evidenceRequirementsChanged($request->user(), $order,
            $change['step'], $change['from'], $change['needs_supplement']);
        return response()->json(['order' => TaskRouting::order($order)]);
    }

    public function workload(Request $request)
    {
        abort_unless(in_array($request->user()->role?->name, ['admin', 'manager', 'engineer'], true), 403);
        abort_unless($request->user()->role?->name !== 'engineer' || $request->user()->hasPermission('orders.view') || $request->user()->hasPermission('orders.create'), 403);
        return response()->json(TaskRouting::workload());
    }

    public function assign(Request $request, Order $order, FactoryOrder $factoryOrder)
    {
        TaskRouting::authorize($request->user(), $order);
        abort_unless((int) $factoryOrder->order_id === (int) $order->id, 404);
        $data = $request->validate(['operator_id' => 'present|nullable|integer|exists:users,id']);
        $previousOperatorId = $factoryOrder->operator_id;
        $step = TaskRouting::assign($request->user(), $factoryOrder, $data['operator_id']);
        TaskNotifier::operatorChanged($request->user(), $order, $step, $previousOperatorId);
        return response()->json(['order' => TaskRouting::order($order), 'workload' => TaskRouting::workload()]);
    }

    public function addWork(Request $request, Order $order)
    {
        TaskRouting::authorize($request->user(), $order);
        $data = $request->validate(['factory_id' => 'required|integer|exists:factories,id',
            'deadline' => 'nullable|date_format:Y-m-d', 'priority' => 'sometimes|in:normal,high,urgent',
            'confirmation_required' => 'sometimes|boolean', 'confirmation_method' => TaskWorkflow::rules()['confirmation_method'],
            'operator_id' => 'sometimes|nullable|integer|exists:users,id',
            'depends_on_id' => 'nullable|integer|exists:factory_orders,id',
            'predecessor_ids' => 'sometimes|array|max:100', 'predecessor_ids.*' => 'required|integer|distinct',
            'files' => 'required|array|min:1|max:200', 'files.*.id' => 'required|integer|distinct|exists:pmp_files,id',
            'files.*.quantity' => 'required|integer|min:1|max:1000000']);
        $step = TaskRouting::addWork($request->user(), $order, $data);
        TaskNotifier::operatorChanged($request->user(), $order, $step, null);
        return response()->json(['order' => TaskRouting::order($order), 'workload' => TaskRouting::workload()], 201);
    }
}
