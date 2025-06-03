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

class EngineerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
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
        // Ստուգել, եթե տվյալ ֆայլերը կան տվյալ գործարանից ու պատվերից
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
            'link_existing_files' => 'required|boolean', // Պարտադիր դարձնենք
            'selected_files' => 'sometimes|array',
            'selected_files.*.id' => 'required_with:selected_files|exists:pmp_files,id',
            'selected_files.*.quantity' => 'required_with:selected_files|integer|min:1',
        ]);

        // Ստեղծել պատվեր
        $order = Order::create($validatedData);

        // Ստեղծել կապված ռեկորդներ
        $order->orderNumber()->create(['number' => $this->generateOrderNumber()]);
        $order->prefixCode()->create(['code' => $this->generateUniquePrefixCode()]);
        $order->dates()->create(['finish_date' => $validatedData['finish_date']]);

        $pmp = Pmp::with('files.factory')->findOrFail($validatedData['pmp_id']);

        // Եթե link_existing_files=false (առանց խմբագրման), ապա վերցնել ԲՈԼՈՐ ֆայլերը
        if (!$validatedData['link_existing_files']) {
            $selectedFiles = $pmp->files->map(function($file) {
                return [
                    'id' => $file->id,
                    'quantity' => 1 // Լռությամբ քանակը 1
                ];
            })->toArray();
        }
        // Հակառակ դեպքում (խմբագրման ռեժիմ) օգտագործել ընտրված ֆայլերը
        else {
            $selectedFiles = $validatedData['selected_files'] ?? [];
        }

        // Մշակել ֆայլերը միայն եթե կան
        if (!empty($selectedFiles)) {
            foreach ($selectedFiles as $selectedFile) {
                // Վալիդացնել որ ֆայլը պատկանում է PMP-ին
                $pmpFile = $pmp->files->firstWhere('id', $selectedFile['id']);
                if (!$pmpFile) {
                    return response()->json(
                        ['error' => "File ID {$selectedFile['id']} does not belong to PMP ID {$validatedData['pmp_id']}"],
                        422
                    );
                }

                // Ստեղծել SelectedFile ռեկորդ
                SelectedFile::create([
                    'order_id' => $order->id,
                    'pmp_file_id' => $selectedFile['id'],
                    'quantity' => $selectedFile['quantity'],
                ]);

                // Ստեղծել կամ ստանալ FactoryOrder
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

                // Կցել ֆայլը FactoryOrder-ին
                $factoryOrder->files()->attach($pmpFile->id, [
                    'quantity' => $selectedFile['quantity'],
                    'material_type' => $pmpFile->material_type,
                    'thickness' => $pmpFile->thickness,
                ]);
            }
        }

        // Ուղարկել email ծանուցում
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
    public function show(string $id): JsonResponse
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

            return response()->json(['order' => $order], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
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

                // Update dates
                $order->dates()->update(['finish_date' => $validatedData['finish_date']]);

                // Handle selected files
                if ($request->link_existing_files && !empty($validatedData['selected_files'])) {
                    $pmp = Pmp::with('files.factory')->findOrFail($validatedData['pmp_id']);
                    $selectedFiles = $request->input('selected_files', []);

                    // Remove existing selected files and factory order files
                    $order->selectedFiles()->delete();
                    foreach ($order->factoryOrders as $factoryOrder) {
                        $factoryOrder->files()->detach();
                    }

                    foreach ($selectedFiles as $selectedFile) {
                        // Validate file belongs to PMP
                        $pmpFile = $pmp->files->firstWhere('id', $selectedFile['id']);
                        if (!$pmpFile) {
                            return response()->json(
                                ['error' => "File ID {$selectedFile['id']} does not belong to PMP ID {$validatedData['pmp_id']}"],
                                422
                            );
                        }

                        // Create SelectedFile record
                        SelectedFile::create([
                            'order_id' => $order->id,
                            'pmp_file_id' => $selectedFile['id'],
                            'quantity' => $selectedFile['quantity'],
                        ]);

                        // Create or get FactoryOrder
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

                        // Attach file to FactoryOrder
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

                // Delete related records
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
