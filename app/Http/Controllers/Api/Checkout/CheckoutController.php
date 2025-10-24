<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CheckoutController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Չթույլատրված մուտք'], 401);
        }

        $basket = $user->basket()->first();
        if (!$basket || empty($basket->items)) {
            return response()->json(['message' => 'Զամբյուղը դատարկ է'], 400);
        }

        return response()->json([
            'items' => $this->formatItems($basket->items),
            'total' => $this->calculateTotal($basket->items),
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Չթույլատրված մուտք'], 401);
        }

        $basket = $user->basket()->first();
        if (!$basket || empty($basket->items)) {
            return response()->json(['message' => 'Զամբյուղը դատարկ է'], 400);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'email' => 'required|email|max:255',
            'address' => 'required|max:255',
            'phone' => 'required|max:20',
            'payment_method' => 'required|in:cash,card',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $purchase = Purchase::create([
            'user_id' => $user->id,
            'total' => $this->calculateTotal($basket->items),
            'status' => 'pending',
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'address' => $request->input('address'),
            'phone' => $request->input('phone'),
            'payment_method' => $request->input('payment_method'),
        ]);

        foreach ($basket->items as $item) {
            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'product_id' => $item['id'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ]);
        }

        $basket->update(['items' => []]);

        return response()->json([
            'message' => 'Պատվերը հաջողությամբ տեղադրվել է',
            'purchase_id' => $purchase->id,
        ], 201);
    }

    private function calculateTotal(array $items)
    {
        return array_reduce($items, function ($total, $item) {
            return $total + ($item['price'] * $item['quantity']);
        }, 0);
    }

    private function formatItems(array $items)
    {
        if (empty($items)) return [];

        return array_map(function ($item) {
            return [
                'id' => $item['id'] ?? null,
                'name' => $item['name'] ?? 'Անհայտ ապրանք',
                'price' => (float) ($item['price'] ?? 0),
                'image' => $item['image'] ?? '/images/placeholder.jpg',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'total' => (float) (($item['price'] ?? 0) * ($item['quantity'] ?? 1)),
            ];
        }, $items);
    }
}
