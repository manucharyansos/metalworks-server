<?php

namespace App\Http\Controllers\Api\Factory;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\FactoryOrderStatus;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FactoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $factory = Factory::all();
        return response()->json($factory, 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|unique:roles|max:255',
        ]);

        $factory = Factory::create([
            'name' => $request->name,
        ]);

        return response()->json($factory, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $factory = Factory::find($id);

        if (!$factory) {
            return response()->json(['message' => 'Factory not found'], 404);
        }

        return response()->json($factory, 200);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'name' => 'required|unique:roles,name,' . $id . '|max:255',
        ]);

        $factory = Factory::find($id);

        if (!$factory) {
            return response()->json(['message' => 'Factory not found'], 404);
        }

        $factory->update([
            'name' => $request->name,
        ]);

        return response()->json($factory, 200);
    }

    public function updateOrder(Request $request, $id): JsonResponse
    {
        $validatedData = $request->validate([
            'factory_id' => 'required|exists:factories,id',
        ]);

        $order = Order::find($id);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        if (isset($validatedData['factory_id'])) {
            $factoryOrderStatuses = $request->input('factory_order_statuses', []);
            FactoryOrderStatus::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'factory_id' => $validatedData['factory_id'],
                ],
                [
                    'status' => $factoryOrderStatuses['status'] ?? null,
                    'description' => $factoryOrderStatuses['description'] ?? null,
                ]
            );
        }

        return response()->json(
            $order->load('orderNumber', 'prefixCode', 'storeLink', 'factories', 'dates', 'factoryOrderStatuses'),
            200
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $factory = Factory::find($id);

        if (!$factory) {
            return response()->json(['message' => 'Factory not found'], 404);
        }

        $factory->delete();

        return response()->json(null, 204);
    }

    public function getOrdersByFactories(Request $request): JsonResponse
    {
        $factoryIds = $request->input('factory_ids');

        if (!$factoryIds) {
            return response()->json(['message' => 'Factory IDs are required'], 400);
        }

        $factoryIdsArray = explode(',', $factoryIds);

        if (empty($factoryIdsArray)) {
            return response()->json(['message' => 'Invalid factory IDs'], 400);
        }

        $orders = Order::whereHas('factories', function ($query) use ($factoryIdsArray) {
            $query->whereIn('factories.id', $factoryIdsArray);
        })
            ->with('orderNumber', 'prefixCode', 'storeLink', 'factories', 'files', 'dates', 'factoryOrderStatuses', 'user')
            ->get();

        return response()->json($orders);
    }



}
