<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BasketController extends Controller
{
    public function index()
    {

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $basket = $user->basket;
        if (!$basket) {
            $basket = Basket::create([
                'user_id' => $user->id,
                'items' => [],
            ]);
        }

        return response()->json($basket);
    }

    public function store(Request $request)
    {

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $product = $request->input('product');
        $basket = $user->basket;
        if (!$basket) {
            $basket = Basket::create([
                'user_id' => $user->id,
                'items' => [],
            ]);
        }

        $items = $basket->items ?? [];
        $existingProductIndex = array_search($product['id'], array_column($items, 'id'));

        if ($existingProductIndex !== false) {
            $items[$existingProductIndex]['quantity'] = ($items[$existingProductIndex]['quantity'] ?? 1) + 1;
        } else {
            $items[] = array_merge($product, ['quantity' => 1]);
        }

        $basket->update(['items' => $items]);

        return response()->json(['basket' => $basket], 201);
    }

    public function show()
    {

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $basket = $user->basket;
        if (!$basket) {
            $basket = Basket::create([
                'user_id' => $user->id,
                'items' => [],
            ]);
        }

        return response()->json($basket);
    }

    public function update(Request $request)
    {

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $basket = $user->basket;
        if (!$basket) {
            $basket = Basket::create([
                'user_id' => $user->id,
                'items' => [],
            ]);
        }

        $items = $basket->items ?? [];
        $product = $request->input('product');
        $action = $request->input('action');

        $existingProductIndex = array_search($product['id'], array_column($items, 'id'));

        if ($existingProductIndex !== false) {
            if ($action === 'decrease' && $items[$existingProductIndex]['quantity'] > 1) {
                $items[$existingProductIndex]['quantity'] -= 1;
            } elseif ($action === 'increase') {
                $items[$existingProductIndex]['quantity'] += 1;
            }
        }

        $basket->update(['items' => $items]);

        return response()->json(['basket' => $basket]);
    }

    public function destroy()
    {

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $basket = $user->basket;
        if ($basket) {
            $basket->delete();
            return response()->json(['message' => 'Basket deleted'], 200);
        }

        return response()->json(['message' => 'Basket not found'], 404);
    }

    public function removeItem(Request $request)
    {

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $basket = $user->basket;
        if (!$basket) {
            return response()->json(['message' => 'Basket not found'], 404);
        }

        $items = $basket->items ?? [];
        $productId = $request->input('product_id');

        $existingProductIndex = array_search($productId, array_column($items, 'id'));

        if ($existingProductIndex !== false) {
            array_splice($items, $existingProductIndex, 1);
            $basket->update(['items' => $items]);
            return response()->json(['basket' => $basket], 200);
        }

        return response()->json(['message' => 'Product not found in basket'], 404);
    }
}
