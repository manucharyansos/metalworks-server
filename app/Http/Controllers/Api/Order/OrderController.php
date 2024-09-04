<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Description;
use App\Models\Order;
use App\Models\PrefixCode;
use App\Models\StoreLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $orders = Order::with('description', 'creator', 'prefixCode', 'storeLink', 'status')->get();
        return response()->json(['orders' => $orders]);
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
        $validatedData = $request->validate([
            'description' => 'required|array',
            'description.name' => 'required|string',
            'description.type' => 'required|string',
            'description.description' => 'nullable|string',
            'prefix_code' => 'required|array',
            'prefix_code.code' => 'required|string',
            'store_link' => 'required|array',
            'store_link.url' => 'required|url',
            'status_id' => 'nullable|exists:statuses,id',
            'roles' => 'array',
            'roles.*' => 'exists:roles,id',
        ]);

        $creatorId = Auth::id();
        $orderNumber = '24.10';
        $description = Description::create($validatedData['description']);
        $prefixCode = PrefixCode::create($validatedData['prefix_code']);
        $storeLink = StoreLink::create($validatedData['store_link']);

        $order = Order::create([
            'order_number' => $orderNumber,
            'description_id' => $description->id,
            'creator_id' => $creatorId,
            'prefix_code_id' => $prefixCode->id,
            'store_link_id' => $storeLink->id,
            'status_id' => $validatedData['status_id'] ?? 1,
        ]);

        if (isset($validatedData['roles'])) {
            $order->roles()->attach($validatedData['roles']);
        }

        return response()->json($order->load('roles'), 201);
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
}
