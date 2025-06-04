<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BasketController extends Controller
{
    public function index()
    {
        $user = Auth::user();
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

    public function show(Basket $basket)
    {
        $user = Auth::user();
        if ($basket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($basket);
    }

    public function update(Request $request, Basket $basket)
    {
        $user = Auth::user();
        if ($basket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
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

    public function destroy(Basket $basket)
    {
        $user = Auth::user();
        if ($basket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $basket->delete();

        return response()->json(['message' => 'Basket deleted'], 200);
    }

    // Նոր մեթոդ՝ առանձին ապրանք ջնջելու համար
    public function removeItem(Request $request, Basket $basket)
    {
        $user = Auth::user();
        if ($basket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
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
