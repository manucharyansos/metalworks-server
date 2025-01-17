<?php

namespace App\Http\Controllers\Api\Factory;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\FactoryOrderStatus;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            'factory_order_statuses.status' => 'nullable|string',
            'factory_order_statuses.canceling' => 'nullable|string',
            'factory_order_statuses.cancel_date' => 'nullable|date',
            'factory_order_statuses.operator_finish_date' => 'nullable|date',
            'factory_order_statuses.admin_confirmation_date' => 'nullable|date',
        ]);

        $order = Order::find($id);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $factoryOrderStatuses = $request->input('factory_order_statuses', []);
        FactoryOrderStatus::updateOrCreate(
            [
                'order_id' => $order->id,
                'factory_id' => $validatedData['factory_id'],
            ],
            [
                'status' => $factoryOrderStatuses['status'] ?? null,
                'canceling' => $factoryOrderStatuses['canceling'] ?? null,
                'cancel_date' => $factoryOrderStatuses['cancel_date'] ?? null,
                'finish_date' => $factoryOrderStatuses['finish_date'] ?? null,
                'operator_finish_date' => $factoryOrderStatuses['operator_finish_date'] ?? null,
                'admin_confirmation_date' => $factoryOrderStatuses['admin_confirmation_date'] ?? null,
            ]
        );

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
            // Բացառել այն պատվերները, որոնց կարգավիճակը `confirmed` է
            ->whereDoesntHave('factoryOrderStatuses', function ($query) {
                $query->where('status', 'confirmed');
            })
            ->with('orderNumber', 'prefixCode', 'storeLink', 'factories', 'files', 'dates', 'factoryOrderStatuses', 'user')
            ->get();

        return response()->json($orders);
    }



    public function confirmOrderStatus($id): JsonResponse
    {
        try {
            $orderStatus = FactoryOrderStatus::where('order_id', $id)->firstOrFail();
            $orderStatus->status = 'confirmed';
            $orderStatus->admin_confirmation_date = now();
            $orderStatus->save();

            return response()->json([
                'message' => 'Order status confirmed successfully.',
                'data' => $orderStatus,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Order status not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while confirming the order status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}
