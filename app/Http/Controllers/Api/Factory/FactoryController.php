<?php

namespace App\Http\Controllers\Api\Factory;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\FactoryOrder;
use App\Models\FactoryOrderFile;
use App\Models\FactoryOrderStatus;
use App\Models\File;
use App\Models\Order;
use App\Models\PmpFiles;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FactoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $factory = Factory::all();
        return response()->json($factory, 200);
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
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|unique:roles|max:255',
        ]);
        $factory = Factory::create([
            'name' => $request->name,
        ]);
        return response()->json($factory, 201);
    }

    /**
     * Display the specified resource.
     */

     public function show($id): JsonResponse
     {
         $factory = Factory::with(['orders' => function ($query) use ($id) {
             $query->wherePivot('admin_confirmation_date', null)
                 ->with([
                     'factoryOrders' => function ($q) use ($id) {
                         $q->where('factory_id', $id)
                             ->whereNull('admin_confirmation_date')
                             ->with('files');
                     },
                     'dates',
                     'creator'
                 ]);
         }])->find($id);

         return response()->json($factory);
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
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'name' => 'required|unique:roles,name,' . $id . '|max:255',
        ]);
        $factory = Factory::find($id);
        if (!$factory) {
            return response()->json(['message' => 'Factory not found'], 404);
        }
        $factory->update([
            'name' => $request->name,
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
            'factory_order.operator_finish_date' => 'nullable|date',
            'factory_order.admin_confirmation_date' => 'nullable|date',
        ]);
        $order = Order::find($id);
        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }
        $factoryOrder = $request->input('factory_order', []);
        FactoryOrder::updateOrCreate(
            [
                'order_id' => $order->id,
                'factory_id' => $validatedData['factory_id'],
            ],
            [
                'status' => $factoryOrder['status'] ?? null,
                'canceling' => $factoryOrder['canceling'] ?? '',
                'cancel_date' => $factoryOrder['cancel_date'] ?? null,
                'finish_date' => $factoryOrder['finish_date'] ?? null,
                'operator_finish_date' => $factoryOrder['operator_finish_date'] ?? null,
                'admin_confirmation_date' => $factoryOrder['admin_confirmation_date'] ?? null,
            ]
        );
        return response()->json(
            $order->load('orderNumber', 'prefixCode', 'storeLink', 'factories', 'dates'),
            200
        );
    }




    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
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
        $factoryIdsArray = explode(',', $factoryIds);
        if (empty($factoryIdsArray)) {
            return response()->json(['message' => 'Invalid factory IDs'], 400);
        }
        $orders = Order::whereHas('factories', function ($query) use ($factoryIdsArray) {
            $query->whereIn('factories.id', $factoryIdsArray);
        })
            ->whereDoesntHave('factoryOrder', function ($query) {
                $query->where('status', 'confirmed');
            })
            ->with('orderNumber', 'prefixCode', 'storeLink', 'factories', 'files', 'dates', 'user')
            ->get();

        return response()->json($orders);
    }



    public function confirmOrderStatus($id): JsonResponse
    {
        try {
            $orderStatus = FactoryOrder::where('order_id', $id)->firstOrFail();
            $orderStatus->status = 'confirmed';
            $orderStatus->admin_confirmation_date = now();
            $orderStatus->save();

            return response()->json([
                'message' => 'Order status confirmed successfully.',
                'data' => $orderStatus,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Order status not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while confirming the order status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getFile($filePath): JsonResponse
    {
        $decodedPath = urldecode($filePath);
        if (!Storage::disk('public')->exists($decodedPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }
        $fileContent = Storage::disk('public')->get($decodedPath);
        $originalName = basename($decodedPath);
        $fileSize = Storage::disk('public')->size($decodedPath);
        $mimeType = Storage::disk('public')->mimeType($decodedPath);

        $base64Content = base64_encode($fileContent);

        return response()->json([
            'path' => $decodedPath,
            'original_name' => $originalName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'content' => $base64Content,
        ], 200);
    }

//    public function getFile($filePath): JsonResponse
//    {
//        $decodedPath = urldecode($filePath);
//
//        $file = PmpFiles::where('path', $decodedPath)->first();
//
//        if (!$file || !Storage::disk('public')->exists($decodedPath)) {
//            return response()->json(['error' => 'File not found'], 404);
//        }
//
//        $fileContent = Storage::disk('public')->get($decodedPath);
//        $base64Content = base64_encode($fileContent);
//
//        return response()->json([
//            'id' => $file->id,
//            'pmp_id' => $file->pmp_id,
//            'remote_number_id' => $file->remote_number_id,
//            'factory_id' => $file->factory_id,
//            'path' => $decodedPath,
//            'original_name' => $file->original_name,
//            'quantity' => $file->quantity,
//            'material_type' => $file->material_type,
//            'thickness' => $file->thickness,
//            'file_size' => Storage::disk('public')->size($decodedPath),
//            'mime_type' => Storage::disk('public')->mimeType($decodedPath),
//            'content' => $base64Content,
//        ], 200);
//    }


    public function downloadFile($filePath): BinaryFileResponse|JsonResponse
    {
        $decodedPath = urldecode($filePath);
        if (!Storage::disk('public')->exists($decodedPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }
        $fullPath = storage_path("app/public/{$decodedPath}");
        return response()->download($fullPath, basename($decodedPath));
    }

}
