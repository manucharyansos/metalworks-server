<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 10);
            $search = $request->query('search', '');

            $query = Order::with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory',
                'factoryOrders.files',
                'selectedFiles.pmpFile',
                'user',
            ])->where('creator_id', auth()->id());

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhereHas('orderNumber', function ($q) use ($search) {
                          $q->where('number', 'like', "%{$search}%");
                      })
                      ->orWhereHas('prefixCode', function ($q) use ($search) {
                          $q->where('code', 'like', "%{$search}%");
                      });
                });
            }

            $orders = $query->paginate($perPage);

            return response()->json([
                'orders' => $orders->items(),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'total' => $orders->total(),
                    'per_page' => $orders->perPage(),
                    'last_page' => $orders->lastPage(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // public function index(): JsonResponse
    // {
    //     return response()->json(Service::all());
    // }

    public function show($id): JsonResponse
    {
        return response()->json([
            'message' => 'Admin details',
            'data' => null
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Admin created successfully',
            'data' => null
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        return response()->json([
            'message' => 'Admin updated successfully',
            'data' => null
        ]);
    }

    public function destroy($id): JsonResponse
    {
        return response()->json([
            'message' => 'Admin deleted successfully'
        ], 204);
    }
}
