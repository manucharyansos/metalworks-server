<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PrefixCode;
use App\Models\StoreLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $orders = Order::with('orderNumber', 'details', 'status', 'prefixCode', 'storeLink')->get();
        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'user_id' => 'required|exists:users,id',
            'details' => 'required|array',
            'details.*.description' => 'required|string',
            'details.*.quantity' => 'required|integer|min:1',
            'details.*.type' => 'required|string',
            'status' => 'nullable|string',
            'store_link.url' => 'nullable|url',
        ]);

        $order = Order::create([
            'user_id' => $validatedData['user_id'],
        ]);

        $order->orderNumber()->create([
            'number' => $this->generateOrderNumber()
        ]);

        $order->details()->createMany($validatedData['details']);
        $order->status()->create([
            'status' => $validatedData['status'] ?? 'waiting',
        ]);
        $order->prefixCode()->create([
            'code' => $this->generateUniquePrefixCode()
        ]);
        if (!empty($validatedData['store_link']['url'])) {
            $order->storeLink()->create([
                'url' => $validatedData['store_link']['url']
            ]);
        }

        return response()->json($order->load('orderNumber', 'details', 'status', 'prefixCode', 'storeLink'), 201);
    }


    public function show($id): JsonResponse
    {
        $order = Order::with('orderNumber', 'details', 'status', 'prefixCode', 'storeLink')->findOrFail($id);
        return response()->json($order);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $validatedData = $request->validate([
            'details' => 'required|array',
            'details.*.description' => 'required|string',
            'details.*.quantity' => 'required|integer|min:1',
            'details.*.type' => 'required|string',
            'status' => 'nullable|string',
            'store_link.url' => 'nullable|url',
        ]);

        $order = Order::findOrFail($id);

        $order->details()->delete(); // Remove old details
        $order->details()->createMany($validatedData['details']); // Add new details

        if ($order->status) {
            $order->status->update(['status' => $validatedData['status'] ?? 'waiting']);
        } else {
            $order->status()->create(['status' => $validatedData['status'] ?? 'waiting']);
        }

        if (!empty($validatedData['store_link']['url'])) {
            $order->storeLink()->updateOrCreate(
                ['order_id' => $order->id],
                ['url' => $validatedData['store_link']['url']]
            );
        }

        return response()->json($order->load('orderNumber', 'details', 'status', 'prefixCode', 'storeLink'), 200);
    }


    // Remove the specified order
    public function destroy($id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $order->delete();

        return response()->json(null, 204);
    }

    private function generateOrderNumber(): string
    {
        $currentMonth = date('m');
        $currentYear = date('Y');
        $sequenceNumber = Order::whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $currentMonth)
                ->count() + 1;

        return sprintf('%s-%s-%04d', $currentYear, $currentMonth, $sequenceNumber);
    }

    private function generateUniquePrefixCode(): string
    {
        $prefixCode = strtoupper(bin2hex(random_bytes(3)));

        while (PrefixCode::where('code', $prefixCode)->exists()) {
            $prefixCode = strtoupper(bin2hex(random_bytes(3)));
        }

        return $prefixCode;
    }
}