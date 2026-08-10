<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = max(10, min((int) $request->query('per_page', 10), 100));
            $search = trim((string) $request->query('search', ''));
            if (mb_strlen($search) > 255) {
                $search = mb_substr($search, 0, 255);
            }

            $query = Order::with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory',
                'factoryOrders.files',
                'factoryOrders.operator:id,name',
                'selectedFiles.pmpFile',
                'user',
                'client.user',
                'creator:id,name',
            ]);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('orderNumber', function ($numberQuery) use ($search) {
                            $numberQuery->where('number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('prefixCode', function ($prefixQuery) use ($search) {
                            $prefixQuery->where('code', 'like', "%{$search}%");
                        });
                });
            }

            $orders = $query->latest('orders.id')->paginate($perPage);

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
        } catch (\Throwable $e) {
            Log::error('Legacy admin order list failed', ['exception' => $e]);

            return response()->json([
                'message' => 'Unable to load orders.',
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        return response()->json([
            'message' => 'Admin details',
            'data' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Admin created successfully',
            'data' => null,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        return response()->json([
            'message' => 'Admin updated successfully',
            'data' => null,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        return response()->json(null, 204);
    }
}
