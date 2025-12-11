<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Mail\OrderCreated;
use App\Models\FileExtension;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\PrefixCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $orders = Order::with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory',
                'factoryOrders.files',
                'selectedFiles.pmpFile',
                'client.user',
                'creator:id,name',
                'logs.user',
                'factoryOrders.operator:id,name',
            ])->get();

            return response()->json(['orders' => $orders], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'user_id' => 'required|exists:users,id',
                'description' => 'required|string',
                'name' => 'required|string',
                'status' => 'nullable|string',
                'factories' => 'nullable|array',
                'factories.*.id' => 'required|exists:factories,id',
                'factories.*.status' => 'nullable|string',
                'store_link.url' => 'nullable|url',
                'finish_date' => 'nullable|string',

            ]);

            $order = Order::create([
                'user_id' => $validatedData['user_id'],
                'name' => $validatedData['name'],
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
                    $factoryOrder = $order->factories()->attach($factory['id']);
                    $order->factoryOrders()->create([
                        'factory_id' => $factory['id'],
                        'status' => $factory['status'] ?? 'waiting',
                    ]);
                }
            }

            $order->dates()->create(['finish_date' => $validatedData['finish_date'] ?? null]);
            if (!empty($validatedData['files'])) {
                foreach ($validatedData['files'] as $file) {
                    $originalName = $file->getClientOriginalName();
                    $extension = $file->getClientOriginalExtension();

                    $fileName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . time() . '.' . $extension;

                    $path = $file->storeAs("uploads/orders/{$order->id}", $fileName, 'public');

                    $order->files()->create([
                        'path' => $path,
                        'original_name' => $originalName,
                    ]);
                }
            }

            $userEmail = User::find($validatedData['user_id'])->email;
            $orderUrl = route('orders.show', ['id' => $order->id]);
            Mail::to($userEmail)->send(new OrderCreated($order, $orderUrl));

            return response()->json($order->load('orderNumber', 'prefixCode', 'storeLink', 'factories', 'dates', 'files'), 201);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show($id): JsonResponse
    {
        $order = Order::with([
            'orderNumber',
            'prefixCode',
            'storeLink',
            'factories',
            'dates',
            'files',
            'factoryOrders.files',
            'creator:id,name',
            'client.user',
            'client',
            'logs.user',
            'factoryOrders.operator:id,name',
        ])->findOrFail($id);

        return response()->json($order);
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'required|string',
                'status' => 'sometimes|required|string|in:pending,in_progress,completed,cancelled', // ակտիվացրեցինք
                'factories' => 'required|array|min:1',
                'factories.*.id' => 'required|exists:factories,id',
                // 'factories.*.status' => 'sometimes|required|string|in:pending,in_progress,completed', // եթե ուզում ես per-factory status
                'store_link.url' => 'nullable|url',
                'finish_date' => 'nullable|date',
            ]);

            /** @var \App\Models\Order $order */
            $order = Order::findOrFail($id);

            // Հին արժեքները log-ի համար
            $oldStatus = $order->status;
            $oldName = $order->name;
            $oldDesc = $order->description;
            $oldFinishDate = optional($order->dates)->finish_date;
            $oldStoreLink = optional($order -> storeLink)->url;
            $oldFactoryIds = $order->factories()->pluck('factories.id')->toArray();

            // 1) Հիմնական դաշտերի թարմացում
            $order->update([
                'name' => $validatedData['name'],
                'description' => $validatedData['description'],
                'status' => $validatedData['status'] ?? $order->status, // կարևոր է!
            ]);

            // 2) Store Link
            if (!empty($validatedData['store_link']['url'])) {
                $order->storeLink()->updateOrCreate(
                    ['order_id' => $order->id],
                    ['url' => $validatedData['store_link']['url']]
                );
            } else {
                $order->storeLink()->delete();
            }

            // 3) Factories + FactoryOrders
            $factoryIds = collect($validatedData['factories'])->pluck('id')->toArray();
            $order->factories()->sync($factoryIds);

            foreach ($validatedData['factories'] as $factory) {
                $order->factoryOrders()->updateOrCreate(
                    [
                        'factory_id' => $factory['id'],
                        'order_id' => $order->id,
                    ],
                    [
                        // 'status' => $factory['status'] ?? 'pending', // ակտիվացրու եթե պետք է per-factory status
                    ]
                );
            }

            // 4) Finish date
            if (array_key_exists('finish_date', $validatedData)) {
                if ($validatedData['finish_date']) {
                    $order->dates()->updateOrCreate(
                        ['order_id' => $order->id],
                        ['finish_date' => $validatedData['finish_date']]
                    );
                } else {
                    $order->dates()->delete();
                }
            }

            // Reload relations
            $order->load(
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory',
                'factoryOrders.files',
                'selectedFiles.pmpFile',
                'client.user',
                'logs.user',
                'creator:id,name',
                'factoryOrders.operator:id,name',
                'storeLink',
                'factories'
            );

            $order['orderNumber'] = $order->orderNumber?->toArray();

            // === LOGIC ՓՈՓՈԽՈՒԹՅՈՒՆՆԵՐԻ ՀԱՄԱՐ ===
            $user = $request->user();
            $changes = [];

            if ($oldName !== $order->name) {
                $changes[] = 'անվանումը փոխվել է';
            }
            if ($oldDesc !== $order->description) {
                $changes[] = 'նկարագրությունը թարմացվել է';
            }
            if ($oldStatus !== $order->status) {
                $changes[] = sprintf(
                    'կարգավիճակը "%s" → "%s"',
                    $oldStatus ?? '—',
                    $order->status ?? '—'
                );
            }

            $newFinishDate = optional($order->dates)->finish_date;
            if ($oldFinishDate != $newFinishDate) {
                $changes[] = sprintf(
                    'ավարտի ամսաթիվը "%s" → "%s"',
                    $oldFinishDate ?? '—',
                    $newFinishDate ?? '—'
                );
            }

            $newStoreLink = optional($order->storeLink)->url;
            if ($oldStoreLink !== $newStoreLink) {
                $changes[] = 'արտաքին հղումը փոխվել է';
            }

            $newFactoryIds = $order->factories()->pluck('factories.id')->toArray();
            sort($oldFactoryIds);
            sort($newFactoryIds);
            if ($oldFactoryIds !== $newFactoryIds) {
                $changes[] = 'գործարանների ցուցակը թարմացվել է';
            }

            // Log
            OrderLog::create([
                'order_id' => $order->id,
                'user_id' => $user?->id,
                'action' => 'order.updated',
                'message' => $changes
                    ? 'Պատվերը թարմացվել է (' . implode(', ', $changes) . ')'
                    : 'Պատվերը թարմացվել է առանց էական փոփոխությունների',
                'meta' => [
                    'from_status' => $oldStatus,
                    'to_status' => $order->status,
                    'old_finish' => $oldFinishDate,
                    'new_finish' => $newFinishDate,
                    'old_factories' => $oldFactoryIds,
                    'new_factories' => $newFactoryIds,
                    'old_store_link' => $oldStoreLink,
                    'new_store_link' => $newStoreLink,
                ],
            ]);

            return response()->json([
                'order' => $order,
                'message' => 'Պատվերը հաջողությամբ թարմացվել է',
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Վավերացման սխալ',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Order update failed', ['exception' => $e, 'order_id' => $id]);
            return response()->json([
                'message' => 'Սխալ պատվերի թարմացման ընթացքում',
                'error' => $e->getMessage(),
            ], 500);
        }
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
