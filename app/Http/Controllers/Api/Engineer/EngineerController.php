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
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */

    public function getFilesForFactoryAndOrder($factoryId, $orderId): JsonResponse
    {
        // Ստուգել, եթե տվյալ ֆայլերը կան տվյալ գործարանից ու պատվերից
        $files = FactoryOrderFile::with('factory', 'order')
            ->where('factory_id', $factoryId)
            ->where('order_id', $orderId)
            ->get();

        // Վերադարձնել ֆայլերը JSON ձևաչափով
        return response()->json(['files' => $files], 200);
    }
/**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'user_id' => 'required|exists:users,id',
                'description' => 'required|string',
                'name' => 'required|string',
                'status' => 'nullable|string',
                'factories' => 'required|array',
                'factories.*.id' => 'required|exists:factories,id',
                'factories.*.files' => 'required|array',
                'factories.*.files.*' => 'required|file|max:10240',
                'factories.*.files.*.quantity' => 'required|integer|min:1',
                'factories.*.files.*.material_type' => 'required|string|max:255',
                'factories.*.files.*.thickness' => 'required|numeric|min:0',
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

            $order->prefixCode()->create([
                'code' => $this->generateUniquePrefixCode(),
            ]);

            if (!empty($validatedData['store_link']['url'])) {
                $order->storeLink()->create([
                    'url' => $validatedData['store_link']['url'],
                ]);
            }

            $order->dates()->create([
                'finish_date' => $validatedData['finish_date'] ?? null,
            ]);

            $orderName = str_replace(' ', '_', strtolower($order->name));
            $orderId = $order->id;

            foreach ($validatedData['factories'] as $factoryData) {
                $factory = Factory::findOrFail($factoryData['id']);
                $factoryName = str_replace(' ', '_', $factory->value);

                $factoryOrder = FactoryOrder::firstOrCreate(
                    [
                        'order_id' => $order->id,
                        'factory_id' => $factory->id,
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

                $directoryPath = "uploads/PMP_{$orderName}_{$orderId}/{$factoryName}";
                Storage::disk('public')->makeDirectory($directoryPath);

                foreach ($factoryData['files'] as $fileIndex => $file) {
                    $originalName = $file->getClientOriginalName();
                    $fileName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $existingFile = $factoryOrder->files()->where('original_name', $originalName)->first();

                    if (!$existingFile) {
                        $path = $file->storeAs(
                            $directoryPath,
                            $fileName,
                            'public'
                        );

                        $quantity = $request->input("factories.{$factoryData['id']}.files_quantity.{$fileIndex}");
                        $materialType = $request->input("factories.{$factoryData['id']}.files_material_type.{$fileIndex}");
                        $thickness = $request->input("factories.{$factoryData['id']}.files_thickness.{$fileIndex}");

                        $factoryOrder->files()->create([
                            'path' => $path,
                            'original_name' => $originalName,
                            'quantity' => $quantity,
                            'material_type' => $materialType,
                            'thickness' => $thickness,
                            ]);
                    }
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

    public function storeWithFiles(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'user_id' => 'required|exists:users,id',
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
            'files.*' => [
                'file',
                'max:2048',
                function ($attribute, $value, $fail) {
                    $allowedExtensions = FileExtension::pluck('extension')->toArray();
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, $allowedExtensions)) {
                        $fail("The {$attribute} must be a valid file type.");
                    }
                },
            ],
        ]);

        $order = Order::create([
            'user_id' => $validatedData['user_id'],
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

        $order->dates()->create(['finish_date' => $validatedData['finish_date'] ?? null]);

        if ($request->has('factories') && is_array($request->factories)) {
            foreach ($request->factories as $index => $factoryData) {
                $factoryId = $factoryData['id'];

                // Ստանալ համապատասխան ֆայլերը
                $files = $request->file('files')[$index] ?? null;

                if ($files) {
                    if (is_array($files)) {
                        foreach ($files as $file) {
                            if ($file->isValid()) {
                                $path = $file->store('uploads/orders/' . $order->id);
                                FactoryOrderFile::create([

                                    'factory_id' => $factoryId,
                                    'order_id' => $order->id,
                                    'path' => $path,
                                    'original_name' => $file->getClientOriginalName(),
                                ]);
                            }
                        }
                    }
                }
            }
        }
        $userEmail = User::find($validatedData['user_id'])->email;
        $orderUrl = route('orders.show', ['id' => $order->id]);
        Mail::to($userEmail)->send(new OrderCreated($order, $orderUrl));

        return response()->json($order->load('orderNumber', 'prefixCode', 'storeLink', 'factories', 'dates', 'files'), 201);
    }

    public function upload(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'order_id' => 'required|exists:orders,id',
                'status' => 'nullable|string',
                'factories' => 'required|array',
                'factories.*.id' => 'required|exists:factories,id',
                'factories.*.files' => 'required|array',
                'factories.*.files.*' => 'required|file|max:10240',
            ]);

            $order = Order::findOrFail($validatedData['order_id']);
            $orderName = str_replace(' ', '_', strtolower($order->name));
            $orderId = $order->id;

            foreach ($validatedData['factories'] as $factoryData) {
                $factory = Factory::findOrFail($factoryData['id']);
                $factoryName = str_replace(' ', '_', $factory->value);
                $factoryOrder = FactoryOrder::firstOrCreate(
                    [
                        'order_id' => $order->id,
                        'factory_id' => $factory->id
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

                $directoryPath = "uploads/PMP_{$orderName}_{$orderId}/{$factoryName}";
                Storage::disk('public')->makeDirectory($directoryPath);

                foreach ($factoryData['files'] as $file) {
                    $originalName = $file->getClientOriginalName();
                    $fileName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                    // Check if the file already exists in the database
                    $existingFile = $factoryOrder->files()->where('original_name', $originalName)->first();

                    if (!$existingFile) {
                        $path = $file->storeAs(
                            $directoryPath,
                            $fileName,
                            'public'
                        );

                        $factoryOrder->files()->create([
                            'path' => $path,
                            'original_name' => $originalName,
                        ]);
                    }
                }
            }

            return response()->json(['message' => 'Files uploaded successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
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
