<?php

namespace App\Http\Controllers\Api\Factory;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\FactoryOrder;
use App\Models\FactoryOrderFile;
use App\Models\FactoryOrderStatus;
use App\Models\Order;
use App\Models\PmpFiles;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FactoryController extends Controller
{
    public function index(): JsonResponse
    {
        $factories = Factory::with('operators:id,name,factory_id')->get();

        return response()->json($factories);
    }

    public function create()
    {
        //
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('factories', 'name')],
        ]);

        $factory = Factory::create([
            'name' => $validated['name'],
        ]);

        return response()->json($factory, 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();

        if ($user?->factory_id && (int) $user->factory_id !== (int) $id && $user->role?->name !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $factory = Factory::with(['orders' => function ($query) use ($id, $user) {
            $query->whereHas('factoryOrders', function ($q) use ($id, $user) {
                $q->where('factory_id', $id)
                    ->whereNull('admin_confirmation_date')
                    ->where(function ($sub) use ($user) {
                        $sub->whereNull('operator_id')
                            ->orWhere('operator_id', $user->id);
                    });
            })
                ->with([
                    'factoryOrders' => function ($q) use ($id, $user) {
                        $q->where('factory_id', $id)
                            ->whereNull('admin_confirmation_date')
                            ->where(function ($sub) use ($user) {
                                $sub->whereNull('operator_id')
                                    ->orWhere('operator_id', $user->id);
                            })
                            ->with([
                                'files',
                                'operator:id,name',
                            ]);
                    },
                    'dates',
                    'creator',
                    'factoryOrders.operator:id,name',
                    'logs',
                ]);
        }])->find($id);

        if (!$factory) {
            return response()->json(['message' => 'Factory not found'], 404);
        }

        return response()->json($factory);
    }

    public function edit(string $id)
    {
        //
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $factory = Factory::find($id);
        if (!$factory) {
            return response()->json(['message' => 'Factory not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('factories', 'name')->ignore($factory->id)],
        ]);

        $factory->update([
            'name' => $validated['name'],
        ]);

        return response()->json($factory, 200);
    }

    public function updateOrder(Request $request, $id): JsonResponse
    {
        $validatedData = $request->validate([
            'factory_id' => 'required|exists:factories,id',
            'factory_order.status' => 'nullable|string',
            'factory_order.canceling' => 'nullable|string',
            'factory_order.cancel_date' => 'nullable|date',
            'factory_order.finish_date' => 'nullable|date',
            'factory_order.operator_finish_date' => 'nullable|date',
            'factory_order.admin_confirmation_date' => 'nullable|date',
        ]);

        $user = $request->user();
        $factoryId = (int) $validatedData['factory_id'];

        if ($user?->factory_id && (int) $user->factory_id !== $factoryId && $user->role?->name !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $factoryOrderData = $request->input('factory_order', []);
        if (
            $user?->role?->name !== 'admin' &&
            array_key_exists('admin_confirmation_date', $factoryOrderData)
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $order = Order::find($id);
        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $belongsToOrder = $order->factories()->where('factories.id', $factoryId)->exists();
        if (!$belongsToOrder) {
            return response()->json(['message' => 'Factory is not assigned to this order'], 422);
        }

        $status = $factoryOrderData['status'] ?? null;

        $fo = FactoryOrder::firstOrNew([
            'order_id' => $order->id,
            'factory_id' => $factoryId,
        ]);

        $oldStatus = $fo->status;

        $fo->status = $status;
        $fo->canceling = $factoryOrderData['canceling'] ?? '';
        $fo->cancel_date = $factoryOrderData['cancel_date'] ?? null;
        $fo->finish_date = $factoryOrderData['finish_date'] ?? null;
        $fo->operator_finish_date = $factoryOrderData['operator_finish_date'] ?? null;
        $fo->admin_confirmation_date = $factoryOrderData['admin_confirmation_date'] ?? $fo->admin_confirmation_date;

        if (!$fo->operator_id && $status && $status !== 'pending') {
            $fo->operator_id = $user->id;
        }

        $fo->save();

        $factoryName = optional($fo->factory)->name ?? ('ID ' . $fo->factory_id);

        \App\Models\OrderLog::create([
            'order_id' => $order->id,
            'user_id' => $user?->id,
            'action' => 'factory_order.status_changed',
            'message' => sprintf(
                'Գործարան "%s" կարգավիճակը փոխվել է "%s" → "%s"',
                $factoryName,
                $oldStatus ?? '—',
                $fo->status ?? '—'
            ),
            'meta' => [
                'factory_id' => $fo->factory_id,
                'from_status' => $oldStatus,
                'to_status' => $fo->status,
            ],
        ]);

        return response()->json(
            $order->load(
                'orderNumber',
                'prefixCode',
                'storeLink',
                'factories',
                'dates',
                'factoryOrders.files',
                'logs.user',
                'factoryOrders.operator:id,name'
            ),
            200
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);

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

        $factoryIdsArray = array_values(array_filter(array_map('intval', explode(',', $factoryIds))));
        if (empty($factoryIdsArray)) {
            return response()->json(['message' => 'Invalid factory IDs'], 400);
        }

        $user = $request->user();
        if ($user?->factory_id && $user->role?->name !== 'admin') {
            foreach ($factoryIdsArray as $factoryId) {
                if ((int) $user->factory_id !== $factoryId) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }
        }

        $orders = Order::whereHas('factories', function ($query) use ($factoryIdsArray) {
            $query->whereIn('factories.id', $factoryIdsArray);
        })
            ->whereDoesntHave('factoryOrders', function ($query) {
                $query->where('status', 'confirmed');
            })
            ->with(
                'orderNumber',
                'prefixCode',
                'storeLink',
                'factories',
                'files',
                'dates',
                'user',
                'creator:id,name'
            )
            ->get();

        return response()->json($orders);
    }

    public function confirmOrderStatus(Request $request, $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $factoryId = $request->input('factory_id');

            if (!$factoryId) {
                return response()->json(['message' => 'factory_id is required'], 422);
            }

            $factoryOrder = FactoryOrder::where('order_id', $id)
                ->where('factory_id', $factoryId)
                ->firstOrFail();

            $confirmedStatus = FactoryOrderStatus::where('key', 'confirmed')->value('value') ?? 'confirmed';

            $factoryOrder->status = $confirmedStatus;
            $factoryOrder->admin_confirmation_date = now();
            $factoryOrder->save();

            $order = $factoryOrder->order;
            $order->loadMissing('factories', 'factoryOrders', 'creator:id,name');
            $order->updateStatusIfAllFactoriesAdminConfirmed();

            return response()->json([
                'message' => 'Order factory status confirmed successfully.',
                'data' => [
                    'factory_order' => $factoryOrder,
                    'order' => $order,
                ],
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Factory order not found.'], 404);
        } catch (\Throwable $e) {
            Log::error('Factory order confirmation failed', [
                'order_id' => $id,
                'factory_id' => $request->input('factory_id'),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'An error occurred while confirming the order status.',
            ], 500);
        }
    }

    public function getFile(Request $request, $filePath): JsonResponse
    {
        $decodedPath = $this->authorizeFilePath($request, $filePath);
        if (!$decodedPath) {
            return response()->json(['error' => 'File not found or access denied'], 404);
        }

        $fileContent = Storage::disk('public')->get($decodedPath);
        $originalName = basename($decodedPath);
        $fileSize = Storage::disk('public')->size($decodedPath);
        $mimeType = Storage::disk('public')->mimeType($decodedPath);

        return response()->json([
            'path' => $decodedPath,
            'original_name' => $originalName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'content' => base64_encode($fileContent),
        ], 200);
    }

    public function downloadFile(Request $request, $filePath): BinaryFileResponse|JsonResponse
    {
        $decodedPath = $this->authorizeFilePath($request, $filePath);
        if (!$decodedPath) {
            return response()->json(['error' => 'File not found or access denied'], 404);
        }

        $fullPath = Storage::disk('public')->path($decodedPath);

        return response()->download($fullPath, basename($decodedPath));
    }

    private function authorizeFilePath(Request $request, string $filePath): ?string
    {
        $decodedPath = ltrim(str_replace('\\', '/', urldecode($filePath)), '/');

        if ($decodedPath === '' || str_contains($decodedPath, '../') || $decodedPath === '..') {
            return null;
        }

        if (!Storage::disk('public')->exists($decodedPath)) {
            return null;
        }

        $user = $request->user();
        if (!$user) {
            return null;
        }

        $directFactoryFile = FactoryOrderFile::with('factoryOrder')
            ->where('path', $decodedPath)
            ->first();

        if ($directFactoryFile) {
            if ($user->role?->name === 'admin' || !$user->factory_id) {
                return $decodedPath;
            }

            return (int) $user->factory_id === (int) optional($directFactoryFile->factoryOrder)->factory_id
                ? $decodedPath
                : null;
        }

        $pmpFile = PmpFiles::where('path', $decodedPath)->first();
        if ($pmpFile) {
            if ($user->role?->name === 'admin' || !$user->factory_id) {
                return $decodedPath;
            }

            $belongsToUsersFactory = FactoryOrder::query()
                ->where('factory_id', $user->factory_id)
                ->whereHas('files', function ($query) use ($pmpFile) {
                    $query->whereKey($pmpFile->id);
                })
                ->exists();

            return $belongsToUsersFactory ? $decodedPath : null;
        }

        // Preserve the existing admin capability for exceptional storage files,
        // while non-admin users may only download files represented in the DB.
        return $user->role?->name === 'admin' ? $decodedPath : null;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role?->name === 'admin', 403, 'Forbidden');
    }
}
