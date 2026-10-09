<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FactoryOrder;
use App\Support\TaskRouting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFactoryOrderController extends Controller
{
    public function updateOperator(Request $request, FactoryOrder $factoryOrder): JsonResponse
    {
        $validated = $request->validate(['operator_id' => ['present', 'nullable', 'integer', 'exists:users,id']]);
        $updated = TaskRouting::assign($request->user(), $factoryOrder, $validated['operator_id']);

        return response()->json([
            'message' => $updated->operator_id ? 'Աշխատակիցը հաջողությամբ նշանակվեց։' : 'Աշխատանքը վերադարձվեց չնշանակված վիճակի։',
            'factory_order' => $updated->fresh(['operator:id,name', 'factory:id,name,value']),
        ]);
    }
}
