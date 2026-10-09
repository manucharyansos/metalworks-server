<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\{FactoryOrder, Order};
use App\Support\TaskRouting;
use Illuminate\Http\Request;

class TaskRoutingController extends Controller
{
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
        TaskRouting::assign($request->user(), $factoryOrder, $data['operator_id']);
        return response()->json(['order' => TaskRouting::order($order), 'workload' => TaskRouting::workload()]);
    }

    public function addWork(Request $request, Order $order)
    {
        TaskRouting::authorize($request->user(), $order);
        $data = $request->validate(['factory_id' => 'required|integer|exists:factories,id',
            'operator_id' => 'sometimes|nullable|integer|exists:users,id',
            'depends_on_id' => 'nullable|integer|exists:factory_orders,id',
            'files' => 'required|array|min:1|max:200', 'files.*.id' => 'required|integer|distinct|exists:pmp_files,id',
            'files.*.quantity' => 'required|integer|min:1|max:1000000']);
        TaskRouting::addWork($request->user(), $order, $data);
        return response()->json(['order' => TaskRouting::order($order), 'workload' => TaskRouting::workload()], 201);
    }
}
