<?php

namespace App\Http\Controllers;

use App\Models\Products;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BasketController extends Controller
{
    /**
     * Get current user's basket
     */
    public function index()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $basket = $user->basket()->firstOrCreate([], ['items' => []]);

        return response()->json([
            'id' => $basket->id,
            'items' => $this->formatItems($basket->items),
            'created_at' => $basket->created_at,
            'updated_at' => $basket->updated_at
        ]);
    }

    /**
     * Add item to basket
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'sometimes|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $productId = $request->input('product_id');
        $quantity = $request->input('quantity', 1);

        $basket = $user->basket()->firstOrCreate([], ['items' => []]);
        $items = $basket->items;

        // Find existing item index
        $existingIndex = $this->findProductIndex($items, $productId);

        if ($existingIndex !== false) {
            // Update existing item quantity
            $items[$existingIndex]['quantity'] += $quantity;
        } else {
            $product = Products::findOrFail($productId);

            $items[] = [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'image' => $product->image,
                'quantity' => $quantity,
                'added_at' => now()->toDateTimeString()
            ];
        }

        $basket->update(['items' => $items]);

        return response()->json([
            'message' => 'Product added to basket',
            'basket' => [
                'id' => $basket->id,
                'items' => $this->formatItems($items),
                'item_count' => count($items),
                'total' => $this->calculateTotal($items)
            ]
        ], 201);
    }

    /**
     * Update basket item quantity
     */
    public function update(Request $request, $itemId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $validator = Validator::make($request->all(), [
            'quantity' => 'required_if:action,set|integer|min:1',
            'action' => 'sometimes|in:increase,decrease,set'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $basket = $user->basket()->firstOrFail();
        $items = $basket->items;
        $action = $request->input('action');

        $itemIndex = $this->findProductIndex($items, $itemId);

        if ($itemIndex === false) {
            return response()->json(['message' => 'Item not found in basket'], 404);
        }

        // Perform the requested action
        switch ($action) {
            case 'increase':
                $items[$itemIndex]['quantity'] += 1;
                break;

            case 'decrease':
                if ($items[$itemIndex]['quantity'] <= 1) {
                    return response()->json(['message' => 'Quantity cannot be less than 1'], 422);
                }
                $items[$itemIndex]['quantity'] -= 1;
                break;

            case 'set':
                $items[$itemIndex]['quantity'] = $request->input('quantity');
                break;
        }

        $basket->update(['items' => $items]);

        return response()->json([
            'message' => 'Basket updated',
            'basket' => [
                'id' => $basket->id,
                'items' => $this->formatItems($items),
                'item_count' => count($items),
                'total' => $this->calculateTotal($items)
            ]
        ]);
    }

    /**
     * Remove item from basket
     */
    public function removeItem(Request $request, $itemId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $basket = $user->basket()->firstOrFail();
        $items = $basket->items;
        $itemIndex = $this->findProductIndex($items, $itemId);

        if ($itemIndex === false) {
            return response()->json(['message' => 'Item not found in basket'], 404);
        }

        array_splice($items, $itemIndex, 1);
        $basket->update(['items' => $items]);

        return response()->json([
            'message' => 'Item removed from basket',
            'basket' => [
                'id' => $basket->id,
                'items' => $this->formatItems($items),
                'item_count' => count($items),
                'total' => $this->calculateTotal($items)
            ]
        ]);
    }


    /**
     * Clear the entire basket
     */
    public function destroy($basketId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $basket = $user->basket()->where('id', $basketId)->firstOrFail();
        $basket->update(['items' => []]);

        return response()->json([
            'message' => 'Basket cleared',
            'basket' => [
                'id' => $basket->id,
                'items' => [],
                'item_count' => 0,
                'total' => 0
            ]
        ]);
    }

    /**
     * Helper to find product index in items array
     */
    private function findProductIndex(array $items, $productId)
    {
        foreach ($items as $index => $item) {
            if ($item['id'] == $productId) {
                return $index;
            }
        }
        return false;
    }

    /**
     * Calculate basket total
     */
    private function calculateTotal(array $items)
    {
        return array_reduce($items, function ($total, $item) {
            return $total + ($item['price'] * $item['quantity']);
        }, 0);
    }

    /**
     * Format items for response
     */
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
                'total' => (float) (($item['price'] ?? 0) * ($item['quantity'] ?? 1))
            ];
        }, $items);
    }

    public function current()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $basket = $user->basket()->firstOrCreate([], ['items' => []]);

        return response()->json([
            'id' => $basket->id,
            'items' => $this->formatItems($basket->items),
            'item_count' => count($basket->items),
            'total' => $this->calculateTotal($basket->items),
            'created_at' => $basket->created_at,
            'updated_at' => $basket->updated_at
        ]);
    }
}
