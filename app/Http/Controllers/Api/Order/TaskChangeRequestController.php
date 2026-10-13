<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\{Order, TaskChangeRequest};
use App\Support\{TaskAccess, TaskChangeRequests, TaskNotifier, TaskRouting};
use Illuminate\Http\Request;

class TaskChangeRequestController extends Controller
{
    /** The operator who asked (or management) takes the request back. */
    public function withdraw(Request $request, TaskChangeRequest $taskChangeRequest)
    {
        [$changeRequest, $order, $step, $changed] = TaskChangeRequests::withdraw($request->user(), $taskChangeRequest);
        if ($changed) TaskNotifier::requestWithdrawn($request->user(), $order, $step, $changeRequest);
        $order = Order::whereKey($order->id)->firstOrFail()
            ->load('orderNumber', 'prefixCode', 'dates', 'factoryOrders.files', 'factoryOrders.factory', 'factoryOrders.operator:id,name', 'factoryOrders.pendingRequest', 'creator:id,name');

        return response()->json(TaskAccess::restrictOperatorRelations($order, $request->user()));
    }

    /** Only the engineer who created the task approves or declines. */
    public function decide(Request $request, TaskChangeRequest $taskChangeRequest)
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,decline',
            'note' => 'nullable|string|max:1000',
            'resolution' => 'nullable|in:'.implode(',', TaskChangeRequests::RESOLUTIONS),
            'operator_id' => 'nullable|integer|exists:users,id',
        ]);
        $decided = TaskChangeRequests::decide($request->user(), $taskChangeRequest, $data['decision'], $data);
        $order = Order::whereKey($decided->order_id)->firstOrFail();

        return response()->json(['request' => $decided, 'order' => TaskRouting::order($order), 'workload' => TaskRouting::workload()]);
    }
}
