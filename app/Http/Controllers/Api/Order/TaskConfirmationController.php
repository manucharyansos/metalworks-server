<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\{FactoryOrder, Order, OrderLog};
use App\Support\TaskWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskConfirmationController extends Controller
{
    public function confirm(Request $request, FactoryOrder $factoryOrder)
    {
        $order = DB::transaction(function () use ($request, $factoryOrder) {
            $order = Order::whereKey($factoryOrder->order_id)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->role?->name === 'engineer' && (int) $order->creator_id === (int) $request->user()->id, 403);
            $step = FactoryOrder::whereKey($factoryOrder->id)->lockForUpdate()->firstOrFail();
            if ($step->engineer_confirmation_at) return $order; // Retrying cannot change the reviewer/date.
            $methods = TaskWorkflow::evidenceMethods($step->confirmation_method);
            if (!$step->awaiting_engineer_confirmation || !$step->operator_finish_date
                || !$methods
                || (in_array('photo', $methods, true) && !$step->evidence_photo_path)
                || (in_array('text', $methods, true) && !trim((string) $step->evidence_text))) {
                throw ValidationException::withMessages(['confirmation' => ['Ավարտը և պահանջված հավաստումը դեռ չեն ուղարկվել։']]);
            }
            $step->update(['engineer_confirmation_at' => now(), 'engineer_confirmation_user_id' => $request->user()->id, 'completed_at' => now()]);
            OrderLog::create(['order_id' => $order->id, 'user_id' => $request->user()->id, 'action' => 'factory_order.engineer_confirmed',
                'message' => 'Ինժեները հաստատել է արտադրամասի աշխատանքի ավարտը։', 'meta' => ['factory_order_id' => $step->id, 'factory_id' => $step->factory_id]]);
            $order->updateStatusIfAllFactoriesCompleted();
            return $order;
        });
        return response()->json(['order' => $order->fresh()->load('orderNumber', 'prefixCode', 'dates', 'factoryOrders.factory', 'factoryOrders.files', 'factoryOrders.operator:id,name', 'selectedFiles.pmpFile', 'creator:id,name', 'logs.user')]);
    }

    public function retired()
    {
        return response()->json(['message' => 'Ավարտը հաստատում է առաջադրանքը ստեղծող ինժեները։'], 403);
    }
}
