<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function getOrders(): JsonResponse
    {
        $orders = Order::with('orderNumber', 'details', 'status', 'prefixCode', 'storeLink')->get();
        return response()->json($orders);
    }

    public function index()
    {
        return response()->json(Service::all());
    }

    public function show($id)
    {
        return response()->json([
            'message' => 'Admin details',
            'data' => null
        ]);
    }

    public function store(Request $request)
    {
        return response()->json([
            'message' => 'Admin created successfully',
            'data' => null
        ], 201);
    }

    public function update(Request $request, $id)
    {
        return response()->json([
            'message' => 'Admin updated successfully',
            'data' => null
        ]);
    }

    public function destroy($id)
    {
        return response()->json([
            'message' => 'Admin deleted successfully'
        ], 204);
    }
}
