<?php

namespace App\Http\Controllers\Api\Engineer;

use App\Http\Controllers\Controller;
use App\Mail\OrderCreated;
use App\Models\Factory;
use App\Models\FactoryOrder;
use App\Models\FactoryOrderFile;
use App\Models\FileExtension;
use App\Models\Order;
use App\Models\PrefixCode;
use App\Models\User;
use App\Models\Pmp;
use App\Models\SelectedFile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Validation\ValidationException;

class EngineerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 10);
            $search  = trim((string) $request->query('search', ''));

            $user = $request->user();

            $q = Order::with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory',
                'factoryOrders.files',
                'factoryOrders.operator:id,name',
                'selectedFiles.pmpFile',
                'user',
                'creator:id,name',
            ])->visibleTo($user);

            $isAdmin = optional($user->role)->name === 'admin';
            if (!$isAdmin) {
                $q->where('creator_id', $user->id);
            }

            if ($search !== '') {
                $q->where(function ($qq) use ($search) {
                    $qq->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('orderNumber', fn($w) => $w->where('number', 'like', "%{$search}%"))
                        ->orWhereHas('prefixCode', fn($w) => $w->where('code', 'like', "%{$search}%"));
                });
            }

            $p = $q->orderByDesc('created_at')->paginate($perPage);

            return response()->json([
                'orders' => $p->items(),
                'pagination' => [
                    'current_page' => $p->currentPage(),
                    'total'        => $p->total(),
                    'per_page'     => $p->perPage(),
                    'last_page'    => $p->lastPage(),
                    'from'         => $p->firstItem(),
                    'to'           => $p->lastItem(),
                    'next_page_url'=> $p->nextPageUrl(),
                    'prev_page_url'=> $p->previousPageUrl(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    /**
     * Show the form for creating a new resource.
     */
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
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly create dresource in storage.
     */

    public function getFilesForFactoryAndOrder($factoryId, $orderId): JsonResponse
    {
        $files = FactoryOrderFile::with('factory', 'order')
            ->where('factory_id', $factoryId)
            ->where('order_id', $orderId)
            ->get();
        return response()->json(['files' => $files], 200);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'user_id' => 'required|exists:users,id',
                'description' => 'required|string',
                'name' => 'required|string',
                'status' => 'nullable|string',
                'creator_id' => 'required|exists:users,id',
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

            // պատվերը
            $order = Order::create($validatedData);

            // լրացուցիչ կապված մոդելներ
            $order->orderNumber()->create(['number' => $this->generateOrderNumber()]);
            $order->prefixCode()->create(['code' => $this->generateUniquePrefixCode()]);
            $order->dates()->create(['finish_date' => $validatedData['finish_date']]);

            // PMP + factory-ներով
            $pmp = Pmp::with('files.factory')->findOrFail($validatedData['pmp_id']);

            $factoryOperatorsInput = collect($validatedData['factory_operators'] ?? []);

            $factoryOperatorsInput->each(function ($entry) {
                $operator = User::find($entry['user_id']);
                if (!$operator || (int) $operator->factory_id !== (int) $entry['factory_id']) {
                    throw ValidationException::withMessages([
                        'factory_operators' => 'Ընտրված աշխատակիցը չի պատկանում ընտրված արտադրամասին։',
                    ]);
                }
            });

            // factory_id → operator info map
            $factoryOperators = $factoryOperatorsInput
                ->keyBy('factory_id'); // [factory_id => ['factory_id' => ..., 'user_id' => ...]]

            // Որ ֆայլերն են գնում պատվերի մեջ
            if (!$validatedData['link_existing_files']) {
                // եթե link_existing_files = false → օգտագործում ենք PMP-ի ֆայլերը (ըստ remote_number_id եթե տրված է)
                $remoteId = $validatedData['remote_number_id'] ?? null;
                $filesCol = $pmp->files;
                if ($remoteId) {
                    $filesCol = $filesCol->where('remote_number_id', (int) $remoteId);
                }
                $selectedFiles = $filesCol->map(function ($file) {
                    return [
                        'id' => $file->id,
                        'quantity' => 1,
                    ];
                })->values()->toArray();
            } else {
                // եթե true → օգտագործում ենք frontend-ից եկած selected_files
                $selectedFiles = $validatedData['selected_files'] ?? [];
            }

            if (!empty($selectedFiles)) {
                foreach ($selectedFiles as $selectedFile) {
                    $pmpFile = $pmp->files->firstWhere('id', $selectedFile['id']);

                    if (!$pmpFile) {
                        return response()->json(
                            ['error' => "File ID {$selectedFile['id']} does not belong to PMP ID {$validatedData['pmp_id']}"],
                            422
                        );
                    }

                    // կապում ենք ֆայլը պատվերի հետ
                    SelectedFile::create([
                        'order_id' => $order->id,
                        'pmp_file_id' => $selectedFile['id'],
                        'quantity' => $selectedFile['quantity'],
                    ]);

                    // գտնել օպերատոր այս factory-ի համար
                    $operatorData = $factoryOperators->get($pmpFile->factory_id); // array կամ null
                    $operatorId = $operatorData['user_id'] ?? null;

                    // FactoryOrder-ը (մեկ հատ յուրաքանչյուր գործարանի համար)
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

                    // եթե արդեն կար FactoryOrder, բայց հիմա ընտրել ենք operator
                    if ($operatorId && $factoryOrder->operator_id !== $operatorId) {
                        $factoryOrder->operator_id = $operatorId;
                        $factoryOrder->save();
                    }

                    // attach file to factory_order_files pivot
                    $factoryOrder->files()->attach($pmpFile->id, [
                        'quantity' => $selectedFile['quantity'],
                        'material_type' => $pmpFile->material_type,
                        'thickness' => $pmpFile->thickness,
                    ]);
                }
            }

            // նամակ հաճախորդին
            $userEmail = User::find($validatedData['user_id'])->email;
            $orderUrl = route('orders.show', ['id' => $order->id]);
            Mail::to($userEmail)->send(new OrderCreated($order, $orderUrl));

            return response()->json(
                [
                    'message' => 'Order created successfully',
                    'order' => $order->load([
                        'orderNumber',
                        'prefixCode',
                        'dates',
                        'factoryOrders.files',
                        'factoryOrders.operator',
                        'selectedFiles.pmpFile',
                    ]),
                ],
                201
            );
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }





    /**
     * Display the specified resource.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('admin') && $order->creator_id !== $user->id) {
            abort(403, 'Forbidden');
        }

        $order->load([
            'orderNumber','prefixCode','dates',
            'factoryOrders.factory','factoryOrders.files',
            'selectedFiles.pmpFile','user',
        ]);

        return response()->json(['order' => $order], 200);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): JsonResponse
    {
        try {
            $order = Order::with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory',
                'factoryOrders.files',
                'selectedFiles.pmpFile',
                'user',
            ])->findOrFail($id);

            $users = User::select('id', 'name', 'email')->get();
            $pmps = Pmp::with(['remote_number', 'files.factory'])->get();
            $factories = Factory::select('id', 'name', 'value')->get();

            return response()->json([
                'order' => $order,
                'users' => $users,
                'pmps' => $pmps,
                'factories' => $factories,
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'user_id' => 'required|exists:users,id',
                'description' => 'required|string',
                'name' => 'required|string',
                'status' => 'nullable|string',
                'creator_id' => 'required|exists:users,id',
                'finish_date' => 'required|date',
                'remote_number_id' => 'nullable|exists:remote_numbers,id',
                'pmp_id' => 'required|exists:pmps,id',
                'link_existing_files' => 'sometimes|boolean',
                'selected_files' => 'sometimes|array',
                'selected_files.*.id' => 'required|exists:pmp_files,id',
                'selected_files.*.quantity' => 'required|integer|min:1',
            ]);

                $order = Order::findOrFail($id);
                $order->update($validatedData);

                $order->dates()->update(['finish_date' => $validatedData['finish_date']]);

                if ($request->link_existing_files && !empty($validatedData['selected_files'])) {
                    $pmp = Pmp::with('files.factory')->findOrFail($validatedData['pmp_id']);
                    $selectedFiles = $request->input('selected_files', []);

                    $order->selectedFiles()->delete();
                    foreach ($order->factoryOrders as $factoryOrder) {
                        $factoryOrder->files()->detach();
                    }

                    foreach ($selectedFiles as $selectedFile) {
                        $pmpFile = $pmp->files->firstWhere('id', $selectedFile['id']);
                        if (!$pmpFile) {
                            return response()->json(
                                ['error' => "File ID {$selectedFile['id']} does not belong to PMP ID {$validatedData['pmp_id']}"],
                                422
                            );
                        }

                        SelectedFile::create([
                            'order_id' => $order->id,
                            'pmp_file_id' => $selectedFile['id'],
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
                                'material_type' => $pmpFile->material_type,
                                'thickness' => $pmpFile->thickness,
                            ]
                        ]);
                    }
                }

                return response()->json(
                    [
                        'message' => 'Order updated successfully',
                        'order' => $order->load([
                            'orderNumber',
                            'prefixCode',
                            'dates',
                            'factoryOrders.factory',
                            'factoryOrders.files',
                            'selectedFiles.pmpFile',
                        ]),
                    ],
                    200
                );

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
                $order = Order::findOrFail($id);

                $order->selectedFiles()->delete();
                $order->factoryOrders()->each(function ($factoryOrder) {
                    $factoryOrder->files()->detach();
                    $factoryOrder->delete();
                });
                $order->orderNumber()->delete();
                $order->prefixCode()->delete();
                $order->dates()->delete();
                $order->delete();

                return response()->json(['message' => 'Order deleted successfully'], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
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
