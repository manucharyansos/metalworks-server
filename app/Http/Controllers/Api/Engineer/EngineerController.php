<?php

namespace App\Http\Controllers\Api\Engineer;

use App\Http\Controllers\Controller;
use App\Mail\OrderCreated;
use App\Models\Factory;
use App\Models\FactoryOrder;
use App\Models\Order;
use App\Models\Pmp;
use App\Models\PrefixCode;
use App\Models\RemoteNumber;
use App\Models\SelectedFile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EngineerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = max(1, min((int) $request->query('per_page', 10), 100));
            $search = trim((string) $request->query('search', ''));
            $user = $request->user();

            $query = Order::with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory',
                'factoryOrders.files',
                'factoryOrders.operator:id,name',
                'selectedFiles.pmpFile',
                'client.user',
                'creator:id,name',
            ])->where('creator_id', $user->id);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('orderNumber', fn ($w) => $w->where('number', 'like', "%{$search}%"))
                        ->orWhereHas('prefixCode', fn ($w) => $w->where('code', 'like', "%{$search}%"));
                });
            }

            $page = $query->orderByDesc('created_at')->paginate($perPage);

            return response()->json([
                'orders' => $page->items(),
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                    'last_page' => $page->lastPage(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                    'next_page_url' => $page->nextPageUrl(),
                    'prev_page_url' => $page->previousPageUrl(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Engineer order list failed', [
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Unable to load orders'], 500);
        }
    }

    public function create(): JsonResponse
    {
        try {
            $users = User::select('id', 'name', 'email')->get();
            $pmps = Pmp::with(['remote_number', 'files.factory'])->get();
            $factories = Factory::select('id', 'name', 'value')->get();

            return response()->json([
                'users' => $users,
                'pmps' => $pmps,
                'factories' => $factories,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Engineer create-order data load failed', ['exception' => $e]);

            return response()->json(['error' => 'Unable to load order form data'], 500);
        }
    }

    public function getFilesForFactoryAndOrder(Request $request, $factoryId, $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);
        $this->authorizeOwnedOrder($request, $order);

        $factoryOrder = FactoryOrder::with('files')
            ->where('order_id', $order->id)
            ->where('factory_id', $factoryId)
            ->first();

        return response()->json([
            'files' => $factoryOrder?->files ?? [],
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'user_id' => 'required|exists:users,id',
                'description' => 'required|string',
                'name' => 'required|string|max:255',
                'status' => 'nullable|string',
                'finish_date' => 'required|date',
                'remote_number_id' => 'nullable|exists:remote_numbers,id',
                'pmp_id' => 'required|exists:pmps,id',
                'link_existing_files' => 'required|boolean',
                'selected_files' => 'sometimes|array',
                'selected_files.*.id' => 'required_with:selected_files|exists:pmp_files,id',
                'selected_files.*.quantity' => 'required_with:selected_files|integer|min:1',
                'factory_operators' => 'nullable|array',
                'factory_operators.*.factory_id' => 'required|exists:factories,id',
                'factory_operators.*.user_id' => 'required|exists:users,id',
            ]);

            $pmp = Pmp::with('files.factory')->findOrFail($validatedData['pmp_id']);
            $this->validateRemoteBelongsToPmp($validatedData['remote_number_id'] ?? null, $pmp->id);

            $factoryOperatorsInput = collect($validatedData['factory_operators'] ?? []);
            $this->validateFactoryOperators($factoryOperatorsInput);
            $factoryOperators = $factoryOperatorsInput->keyBy('factory_id');

            $selectedFiles = $this->resolveSelectedFiles($validatedData, $pmp);
            $this->validateSelectedFilesBelongToPmp($selectedFiles, $pmp);

            $order = DB::transaction(function () use (
                $request,
                $validatedData,
                $pmp,
                $selectedFiles,
                $factoryOperators
            ) {
                $order = Order::create([
                    'user_id' => $validatedData['user_id'],
                    'creator_id' => $request->user()->id,
                    'name' => $validatedData['name'],
                    'description' => $validatedData['description'],
                    'status' => $validatedData['status'] ?? 'pending',
                    'remote_number_id' => $validatedData['remote_number_id'] ?? null,
                    'link_existing_files' => $validatedData['link_existing_files'],
                ]);

                $order->orderNumber()->create(['number' => $this->generateOrderNumber()]);
                $order->prefixCode()->create(['code' => $this->generateUniquePrefixCode()]);
                $order->dates()->create(['finish_date' => $validatedData['finish_date']]);

                foreach ($selectedFiles as $selectedFile) {
                    $pmpFile = $pmp->files->firstWhere('id', $selectedFile['id']);

                    SelectedFile::create([
                        'order_id' => $order->id,
                        'pmp_file_id' => $pmpFile->id,
                        'quantity' => $selectedFile['quantity'],
                    ]);

                    $operatorData = $factoryOperators->get($pmpFile->factory_id);
                    $operatorId = $operatorData['user_id'] ?? null;

                    $factoryOrder = FactoryOrder::firstOrCreate(
                        [
                            'order_id' => $order->id,
                            'factory_id' => $pmpFile->factory_id,
                        ],
                        [
                            'status' => $validatedData['status'] ?? 'pending',
                            'canceling' => false,
                            'cancel_date' => null,
                            'finish_date' => null,
                            'operator_finish_date' => null,
                            'admin_confirmation_date' => null,
                            'operator_id' => $operatorId,
                        ]
                    );

                    if ($operatorId && (int) $factoryOrder->operator_id !== (int) $operatorId) {
                        $factoryOrder->operator_id = $operatorId;
                        $factoryOrder->save();
                    }

                    $factoryOrder->files()->syncWithoutDetaching([
                        $pmpFile->id => [
                            'quantity' => $selectedFile['quantity'],
                            'material_type' => $pmpFile->material_type,
                            'thickness' => $pmpFile->thickness,
                        ],
                    ]);
                }

                return $order;
            });

            try {
                $userEmail = User::find($validatedData['user_id'])?->email;
                if ($userEmail) {
                    $orderUrl = route('orders.show', ['id' => $order->id]);
                    Mail::to($userEmail)->send(new OrderCreated($order, $orderUrl));
                }
            } catch (\Throwable $mailError) {
                Log::warning('Engineer order created but notification email failed', [
                    'order_id' => $order->id,
                    'exception' => $mailError,
                ]);
            }

            return response()->json([
                'message' => 'Order created successfully',
                'order' => $order->load([
                    'orderNumber',
                    'prefixCode',
                    'dates',
                    'factoryOrders.files',
                    'factoryOrders.operator',
                    'selectedFiles.pmpFile',
                    'client.user',
                    'creator:id,name',
                ]),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Engineer order creation failed', [
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Unable to create order'], 500);
        }
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOwnedOrder($request, $order);

        $order->load([
            'orderNumber',
            'prefixCode',
            'dates',
            'factoryOrders.factory',
            'factoryOrders.files',
            'selectedFiles.pmpFile',
            'user',
            'client.user',
        ]);

        return response()->json(['order' => $order], 200);
    }

    public function edit(Request $request, string $id): JsonResponse
    {
        try {
            $order = Order::with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory',
                'factoryOrders.files',
                'selectedFiles.pmpFile',
                'client.user',
            ])->findOrFail($id);

            $this->authorizeOwnedOrder($request, $order);

            $users = User::select('id', 'name', 'email')->get();
            $pmps = Pmp::with(['remote_number', 'files.factory'])->get();
            $factories = Factory::select('id', 'name', 'value')->get();

            return response()->json([
                'order' => $order,
                'users' => $users,
                'pmps' => $pmps,
                'factories' => $factories,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Order not found'], 404);
        } catch (\Throwable $e) {
            Log::error('Engineer order edit data load failed', [
                'order_id' => $id,
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Unable to load order'], 500);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'user_id' => 'required|exists:users,id',
                'description' => 'required|string',
                'name' => 'required|string|max:255',
                'status' => 'nullable|string',
                'finish_date' => 'required|date',
                'remote_number_id' => 'nullable|exists:remote_numbers,id',
                'pmp_id' => 'required|exists:pmps,id',
                'link_existing_files' => 'sometimes|boolean',
                'selected_files' => 'sometimes|array',
                'selected_files.*.id' => 'required|exists:pmp_files,id',
                'selected_files.*.quantity' => 'required|integer|min:1',
            ]);

            $order = Order::findOrFail($id);
            $this->authorizeOwnedOrder($request, $order);

            $pmp = Pmp::with('files.factory')->findOrFail($validatedData['pmp_id']);
            $this->validateRemoteBelongsToPmp($validatedData['remote_number_id'] ?? null, $pmp->id);

            $selectedFiles = $validatedData['selected_files'] ?? [];
            if (!empty($selectedFiles)) {
                $this->validateSelectedFilesBelongToPmp($selectedFiles, $pmp);
            }

            DB::transaction(function () use ($request, $validatedData, $order, $pmp, $selectedFiles) {
                $order->update([
                    'user_id' => $validatedData['user_id'],
                    'name' => $validatedData['name'],
                    'description' => $validatedData['description'],
                    'status' => $validatedData['status'] ?? $order->status,
                    'remote_number_id' => $validatedData['remote_number_id'] ?? null,
                    'link_existing_files' => $validatedData['link_existing_files'] ?? $order->link_existing_files,
                ]);

                $order->dates()->updateOrCreate(
                    ['order_id' => $order->id],
                    ['finish_date' => $validatedData['finish_date']]
                );

                if (($validatedData['link_existing_files'] ?? false) && !empty($selectedFiles)) {
                    $order->selectedFiles()->delete();
                    foreach ($order->factoryOrders as $factoryOrder) {
                        $factoryOrder->files()->detach();
                    }

                    foreach ($selectedFiles as $selectedFile) {
                        $pmpFile = $pmp->files->firstWhere('id', $selectedFile['id']);

                        SelectedFile::create([
                            'order_id' => $order->id,
                            'pmp_file_id' => $pmpFile->id,
                            'quantity' => $selectedFile['quantity'],
                        ]);

                        $factoryOrder = FactoryOrder::firstOrCreate(
                            [
                                'order_id' => $order->id,
                                'factory_id' => $pmpFile->factory_id,
                            ],
                            [
                                'status' => $validatedData['status'] ?? 'pending',
                                'canceling' => false,
                                'cancel_date' => null,
                                'finish_date' => null,
                                'operator_finish_date' => null,
                                'admin_confirmation_date' => null,
                            ]
                        );

                        $factoryOrder->files()->syncWithoutDetaching([
                            $pmpFile->id => [
                                'quantity' => $selectedFile['quantity'],
                                'material_type' => $pmpFile->material_type,
                                'thickness' => $pmpFile->thickness,
                            ],
                        ]);
                    }
                }
            });

            return response()->json([
                'message' => 'Order updated successfully',
                'order' => $order->fresh()->load([
                    'orderNumber',
                    'prefixCode',
                    'dates',
                    'factoryOrders.factory',
                    'factoryOrders.files',
                    'selectedFiles.pmpFile',
                    'creator:id,name',
                ]),
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Order not found'], 404);
        } catch (\Throwable $e) {
            Log::error('Engineer order update failed', [
                'order_id' => $id,
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Unable to update order'], 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $order = Order::findOrFail($id);
            $this->authorizeOwnedOrder($request, $order);

            DB::transaction(function () use ($order) {
                $order->selectedFiles()->delete();
                $order->factoryOrders()->each(function ($factoryOrder) {
                    $factoryOrder->files()->detach();
                    $factoryOrder->delete();
                });
                $order->orderNumber()->delete();
                $order->prefixCode()->delete();
                $order->dates()->delete();
                $order->delete();
            });

            return response()->json(['message' => 'Order deleted successfully'], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Order not found'], 404);
        } catch (\Throwable $e) {
            Log::error('Engineer order deletion failed', [
                'order_id' => $id,
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Unable to delete order'], 500);
        }
    }

    private function authorizeOwnedOrder(Request $request, Order $order): void
    {
        abort_unless(
            (int) $order->creator_id === (int) $request->user()->id,
            403,
            'Forbidden'
        );
    }

    private function validateRemoteBelongsToPmp(?int $remoteNumberId, int $pmpId): void
    {
        if (!$remoteNumberId) {
            return;
        }

        $belongs = RemoteNumber::whereKey($remoteNumberId)
            ->where('pmp_id', $pmpId)
            ->exists();

        if (!$belongs) {
            throw ValidationException::withMessages([
                'remote_number_id' => ['Ընտրված հեռակա համարը չի պատկանում այս PMP-ին։'],
            ]);
        }
    }

    private function validateFactoryOperators($factoryOperators): void
    {
        $factoryOperators->each(function ($entry) {
            $operator = User::find($entry['user_id']);
            if (!$operator || (int) $operator->factory_id !== (int) $entry['factory_id']) {
                throw ValidationException::withMessages([
                    'factory_operators' => ['Ընտրված աշխատակիցը չի պատկանում ընտրված արտադրամասին։'],
                ]);
            }
        });
    }

    private function resolveSelectedFiles(array $validatedData, Pmp $pmp): array
    {
        if ($validatedData['link_existing_files']) {
            return $validatedData['selected_files'] ?? [];
        }

        $files = $pmp->files;
        if (!empty($validatedData['remote_number_id'])) {
            $files = $files->where('remote_number_id', (int) $validatedData['remote_number_id']);
        }

        return $files->map(fn ($file) => [
            'id' => $file->id,
            'quantity' => 1,
        ])->values()->toArray();
    }

    private function validateSelectedFilesBelongToPmp(array $selectedFiles, Pmp $pmp): void
    {
        foreach ($selectedFiles as $selectedFile) {
            if (!$pmp->files->contains('id', (int) $selectedFile['id'])) {
                throw ValidationException::withMessages([
                    'selected_files' => ['Ընտրված ֆայլերից մեկը չի պատկանում այս PMP-ին։'],
                ]);
            }
        }
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
