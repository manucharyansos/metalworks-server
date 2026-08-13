<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Mail\OrderCreated;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\PrefixCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
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
        } catch (\Throwable $e) {
            Log::error('Order list failed', ['exception' => $e]);

            return response()->json(['message' => 'Unable to load orders'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $storedPaths = [];

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
                'files' => 'nullable|array',
                'files.*' => 'file|max:51200',
            ]);

            $order = DB::transaction(function () use ($request, $validatedData, &$storedPaths) {
                $order = Order::create([
                    'user_id' => $validatedData['user_id'],
                    'creator_id' => $request->user()->id,
                    'name' => $validatedData['name'],
                    'description' => $validatedData['description'],
                    'status' => $validatedData['status'] ?? 'pending',
                ]);

                $order->orderNumber()->create([
                    'number' => $this->generateOrderNumber(),
                ]);

                $order->prefixCode()->create([
                    'code' => $this->generateUniquePrefixCode(),
                ]);

                if (!empty($validatedData['store_link']['url'])) {
                    $order->storeLink()->create([
                        'url' => $validatedData['store_link']['url'],
                    ]);
                }

                if (!empty($validatedData['factories'])) {
                    foreach ($validatedData['factories'] as $factory) {
                        // factory_orders is both the pivot table and the FactoryOrder model table.
                        // Creating one FactoryOrder is enough; attaching as well would duplicate the row.
                        $order->factoryOrders()->create([
                            'factory_id' => $factory['id'],
                            'status' => $factory['status'] ?? 'waiting',
                        ]);
                    }
                }

                $order->dates()->create([
                    'finish_date' => $validatedData['finish_date'] ?? null,
                ]);

                foreach ($request->file('files', []) as $file) {
                    $originalName = $file->getClientOriginalName();
                    $extension = $file->getClientOriginalExtension();
                    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                    $fileName = $baseName . '_' . bin2hex(random_bytes(4));

                    if ($extension !== '') {
                        $fileName .= '.' . $extension;
                    }

                    $path = $file->storeAs("uploads/orders/{$order->id}", $fileName, 'public');
                    $storedPaths[] = $path;

                    $order->files()->create([
                        'path' => $path,
                        'original_name' => $originalName,
                    ]);
                }

                return $order;
            });

            // Email failure must not turn a successfully-created order into a frontend 500.
            try {
                $userEmail = User::findOrFail($validatedData['user_id'])->email;
                $orderUrl = route('orders.show', ['id' => $order->id]);
                Mail::to($userEmail)->send(new OrderCreated($order, $orderUrl));
            } catch (\Throwable $mailError) {
                Log::warning('Order created but notification email failed', [
                    'order_id' => $order->id,
                    'exception' => $mailError,
                ]);
            }

            return response()->json(
                $order->load('orderNumber', 'prefixCode', 'storeLink', 'factories', 'dates', 'files'),
                201
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Վավերացման սխալ',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            Log::error('Order creation failed', [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Սխալ պատվերի ստեղծման ընթացքում',
            ], 500);
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
                'status' => 'sometimes|required|string|in:pending,in_progress,completed,cancelled',
                'factories' => 'required|array|min:1',
                'factories.*.id' => 'required|exists:factories,id',
                'store_link.url' => 'nullable|url',
                'finish_date' => 'nullable|date',
            ]);

            $order = DB::transaction(function () use ($request, $validatedData, $id) {
                /** @var \App\Models\Order $order */
                $order = Order::findOrFail($id);

                $oldStatus = $order->status;
                $oldName = $order->name;
                $oldDesc = $order->description;
                $oldFinishDate = optional($order->dates)->finish_date;
                $oldStoreLink = optional($order->storeLink)->url;
                $oldFactoryIds = $order->factories()->pluck('factories.id')->toArray();

                $order->update([
                    'name' => $validatedData['name'],
                    'description' => $validatedData['description'],
                    'status' => $validatedData['status'] ?? $order->status,
                ]);

                if (!empty($validatedData['store_link']['url'])) {
                    $order->storeLink()->updateOrCreate(
                        ['order_id' => $order->id],
                        ['url' => $validatedData['store_link']['url']]
                    );
                } else {
                    $order->storeLink()->delete();
                }

                $factoryIds = collect($validatedData['factories'])->pluck('id')->map(fn ($id) => (int) $id)->all();

                // Remove only factories that are no longer assigned, then preserve existing rows/statuses for retained factories.
                $order->factoryOrders()->whereNotIn('factory_id', $factoryIds)->delete();

                foreach ($factoryIds as $factoryId) {
                    $order->factoryOrders()->firstOrCreate(
                        ['factory_id' => $factoryId],
                        ['status' => 'waiting']
                    );
                }

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

                OrderLog::create([
                    'order_id' => $order->id,
                    'user_id' => $request->user()?->id,
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

                return $order;
            });

            return response()->json([
                'order' => $order,
                'message' => 'Պատվերը հաջողությամբ թարմացվել է',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Վավերացման սխալ',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Order update failed', [
                'exception' => $e,
                'order_id' => $id,
            ]);

            return response()->json([
                'message' => 'Սխալ պատվերի թարմացման ընթացքում',
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
