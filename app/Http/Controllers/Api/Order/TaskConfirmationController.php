<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\{FactoryOrder, Order, OrderLog};
use App\Support\{TaskNotifier, TaskWorkflow};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskConfirmationController extends Controller
{
    public function confirm(Request $request, FactoryOrder $factoryOrder)
    {
        $confirmed = false;
        $order = DB::transaction(function () use ($request, $factoryOrder, &$confirmed) {
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
            $confirmed = true;
            return $order;
        });
        if ($confirmed) TaskNotifier::finishConfirmed($request->user(), $order, FactoryOrder::findOrFail($factoryOrder->id));
        return response()->json(['order' => $this->payload($order)]);
    }

    /**
     * The engineer is not satisfied with the submitted proof. The job goes back
     * to the operator with a note; the old proof stays in the history.
     */
    public function returnForRework(Request $request, FactoryOrder $factoryOrder)
    {
        $data = $request->validate(['note' => 'required|string|max:1000']);
        $note = trim($data['note']);
        if ($note === '') throw ValidationException::withMessages(['note' => ['Նշեք՝ ինչ պետք է շտկվի։']]);
        $order = DB::transaction(function () use ($request, $factoryOrder, $note) {
            $order = Order::whereKey($factoryOrder->order_id)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->role?->name === 'engineer' && (int) $order->creator_id === (int) $request->user()->id, 403);
            $step = FactoryOrder::whereKey($factoryOrder->id)->lockForUpdate()->firstOrFail();
            if (!$step->awaiting_engineer_confirmation) {
                throw ValidationException::withMessages(['confirmation' => ['Վերադարձնել կարելի է միայն հաստատմանը սպասող աշխատանքը։']]);
            }
            OrderLog::create(['order_id' => $order->id, 'user_id' => $request->user()->id, 'action' => 'factory_order.finish_returned',
                'message' => 'Ինժեները վերադարձրել է աշխատանքը վերանայման՝ '.$note,
                'meta' => ['factory_order_id' => $step->id, 'factory_id' => $step->factory_id, 'note' => $note,
                    'previous_evidence_text' => $step->evidence_text, 'previous_evidence_photo_path' => $step->evidence_photo_path]]);
            $step->update(['status' => 'confirmed', 'operator_finish_date' => null, 'finish_date' => null, 'completed_at' => null,
                'evidence_text' => null, 'evidence_photo_path' => null, 'return_note' => $note, 'returned_at' => now()]);
            return $order;
        });
        TaskNotifier::finishReturned($request->user(), $order, FactoryOrder::findOrFail($factoryOrder->id), $note);
        return response()->json(['order' => $this->payload($order)]);
    }

    private function payload(Order $order): Order
    {
        return $order->fresh()->load('orderNumber', 'prefixCode', 'dates', 'factoryOrders.factory', 'factoryOrders.files', 'factoryOrders.operator:id,name', 'factoryOrders.pendingRequest', 'selectedFiles.pmpFile', 'creator:id,name', 'logs.user');
    }

    public function retired()
    {
        return response()->json(['message' => 'Ավարտը հաստատում է առաջադրանքը ստեղծող ինժեները։'], 403);
    }
}
