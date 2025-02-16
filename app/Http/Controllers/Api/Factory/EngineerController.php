<?php

namespace App\Http\Controllers\Api\Factory;

use App\Http\Controllers\Controller;
use App\Mail\OrderCreated;
use App\Models\FactoryFile;
use App\Models\FileExtension;
use App\Models\Order;
use App\Models\PrefixCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EngineerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    public function getFilesForFactoryAndOrder($factoryId, $orderId): JsonResponse
    {
        // Ստուգել, եթե տվյալ ֆայլերը կան տվյալ գործարանից ու պատվերից
        $files = FactoryFile::with('factory', 'order')
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
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'nullable|file|mimes:jpg,png,pdf,dxf',
            'factory_id' => 'required|exists:factories,id',
            'order_id' => 'required|exists:orders,id',
        ]);

        foreach ($request->file('files') as $index => $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile) {
                $path = $file->store('uploads/orders/' . $request->order_id);
                $originalName = $file->getClientOriginalName();
            } else {
                $path = $request->input('files')[$index];
                $originalName = $request->input('original_name')[$index];
            }

            FactoryFile::create([
                'factory_id' => $request->factory_id,
                'order_id' => $request->order_id,
                'path' => $path,
                'original_name' => $originalName,
            ]);
        }

        return response()->json(['message' => 'Files uploaded successfully']);
    }



    public function storeWithFiles(Request $request)
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
                                FactoryFile::create([

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



    /**
     * Display the specified resource.
     */
    public function show(string $id)
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
