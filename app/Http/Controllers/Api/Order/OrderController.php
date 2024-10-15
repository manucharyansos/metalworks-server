<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Mail\OrderCreated;
use App\Models\Order;
use App\Models\PrefixCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $orders = Order::with('orderNumber', 'prefixCode', 'storeLink', 'factories', 'dates', 'factoryOrderStatuses.factory', 'files')->get();
        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        Log::info($request->file('files'));
        $validatedData = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'description' => 'required|string',
            'quantity' => 'required|integer|min:1',
            'name' => 'required|string',
            'status' => 'nullable|string',
            'factories' => 'nullable|array',
            'factories.*.id' => 'required|exists:factories,id',
            'factories.*.status' => 'nullable|string',
            'store_link.url' => 'nullable|url',
            'finish_date' => 'nullable|string',
            'files' => 'nullable|array',
            'files.*' => 'file|mimes:step,dxf,png,jpg,eps,pdf|max:2048',
        ]);

        $order = Order::create([
            'client_id' => $validatedData['client_id'],
            'name' => $validatedData['name'],
            'quantity' => $validatedData['quantity'],
            'description' => $validatedData['description'],
            'status' => $validatedData['status'] ?? 'pending',
        ]);

        $order->orderNumber()->create([
            'number' => $this->generateOrderNumber(),
        ]);

        $order->prefixCode()->create(['code' => $this->generateUniquePrefixCode()]);

        if (!empty($validatedData['store_link']['url'])) {
            $order->storeLink()->create(['url' => $validatedData['store_link']['url']]);
        }

        if (!empty($validatedData['factories'])) {
            foreach ($validatedData['factories'] as $factory) {
                $order->factories()->attach($factory['id']);
                $order->factoryOrderStatuses()->create([
                    'factory_id' => $factory['id'],
                    'status' => $factory['status'] ?? 'waiting',
                ]);
            }
        }

        $order->dates()->create(['finish_date' => $validatedData['finish_date'] ?? null]);

        if (!empty($validatedData['files'])) {
            foreach ($validatedData['files'] as $file) {
                $path = $file->store("uploads/orders/{$order->id}", 'public');
                $order->files()->create(['path' => $path]);
            }
        }

        $email = $order->client->email_address;
        $orderUrl = route('orders.show', ['id' => $order->id]);
        Mail::to($email)->send(new OrderCreated($order, $orderUrl));

        return response()->json($order->load('orderNumber', 'prefixCode', 'storeLink', 'factories', 'dates', 'factoryOrderStatuses.factory', 'files'), 201);
    }




    public function show($id): JsonResponse
    {
        $order = Order::with('orderNumber', 'prefixCode', 'storeLink', 'factories', 'factoryOrderStatuses.factory', 'dates', 'files')->findOrFail($id);
        return response()->json($order);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $validatedData = $request->validate([
            'description' => 'required|string',
            'quantity' => 'required|integer|min:1',
            'name' => 'required|string',
            'status' => 'nullable|string',
            'factories' => 'required|array',
            'factories.*.id' => 'required|exists:factories,id',
            'factories.*.status' => 'nullable|string',
            'store_link.url' => 'nullable|url',
            'finish_date' => 'nullable|string',
            'files' => 'nullable|array',
            'files.*' => 'file|mimes:step,dxf,png,jpg,eps,pdf|max:2048',
        ]);

        $order = Order::findOrFail($id);

        $order->update([
            'description' => $validatedData['description'],
            'quantity' => $validatedData['quantity'],
            'name' => $validatedData['name'],
            'status' => $validatedData['status'] ?? $order->status,
        ]);

        $order->details()->delete();
        $order->details()->createMany($validatedData['details']);

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

        if (!empty($validatedData['factories'])) {
            $factoryIds = array_column($validatedData['factories'], 'id');
            $order->factories()->sync($factoryIds);

            foreach ($validatedData['factories'] as $factory) {
                $order->factoryOrderStatuses()->updateOrCreate(
                    ['factory_id' => $factory['id'], 'order_id' => $order->id],
                    ['status' => $factory['status'] ?? 'waiting']
                );
            }
        }

        if (isset($validatedData['finish_date'])) {
            $order->dates()->update(['finish_date' => $validatedData['finish_date']]);
        }

        if (!empty($validatedData['files'])) {
            foreach ($order->files as $existingFile) {
                $existingFile->delete();
            }
            foreach ($validatedData['files'] as $file) {
                $path = $file->store("uploads/orders/{$order->id}", 'public');
                $order->files()->create(['path' => $path]);
            }
        }

        return response()->json($order->load('orderNumber', 'details', 'status', 'prefixCode', 'storeLink', 'factories', 'dates', 'factoryOrderStatuses.factory', 'files'), 200);
    }



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
