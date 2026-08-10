<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FactoryOrder;
use App\Models\OrderLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFactoryOrderController extends Controller
{
    public function updateOperator(Request $request, FactoryOrder $factoryOrder): JsonResponse
    {
        $validated = $request->validate([
            'operator_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($factoryOrder->admin_confirmation_date) {
            return response()->json([
                'message' => 'Հաստատված factory քայլի աշխատակցին այլևս հնարավոր չէ փոխել։',
            ], 422);
        }

        $newOperator = null;
        if (!empty($validated['operator_id'])) {
            $newOperator = User::query()
                ->with('role:id,name')
                ->findOrFail((int) $validated['operator_id']);

            if ((int) $newOperator->factory_id !== (int) $factoryOrder->factory_id) {
                return response()->json([
                    'message' => 'Ընտրված աշխատակիցը չի պատկանում այս արտադրամասին։',
                ], 422);
            }

            if (in_array($newOperator->role?->name, ['admin', 'manager', 'engineer', 'authenticatedUser'], true)) {
                return response()->json([
                    'message' => 'Այս օգտատերը չի կարող նշանակվել որպես factory operator։',
                ], 422);
            }
        }

        $result = DB::transaction(function () use ($request, $factoryOrder, $newOperator) {
            $locked = FactoryOrder::query()
                ->with(['operator:id,name', 'factory:id,name', 'order:id,name'])
                ->lockForUpdate()
                ->findOrFail($factoryOrder->id);

            if ($locked->admin_confirmation_date) {
                abort(422, 'Factory order is already confirmed.');
            }

            $previousOperator = $locked->operator;
            $locked->operator_id = $newOperator?->id;
            $locked->save();

            OrderLog::create([
                'order_id' => $locked->order_id,
                'user_id' => $request->user()?->id,
                'action' => 'factory_order.operator_changed',
                'message' => sprintf(
                    'Արտադրամաս "%s" աշխատակիցը փոխվել է "%s" → "%s"',
                    $locked->factory?->name ?? ('ID ' . $locked->factory_id),
                    $previousOperator?->name ?? 'չնշանակված',
                    $newOperator?->name ?? 'չնշանակված'
                ),
                'meta' => [
                    'factory_order_id' => $locked->id,
                    'factory_id' => $locked->factory_id,
                    'from_operator_id' => $previousOperator?->id,
                    'to_operator_id' => $newOperator?->id,
                ],
            ]);

            return $locked->fresh(['operator:id,name,factory_id', 'factory:id,name,value']);
        });

        return response()->json([
            'message' => $newOperator
                ? 'Աշխատակիցը հաջողությամբ նշանակվեց։'
                : 'Factory քայլը վերադարձվեց չնշանակված վիճակի։',
            'factory_order' => $result,
        ]);
    }
}
