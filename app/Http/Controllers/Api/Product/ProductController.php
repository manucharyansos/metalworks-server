<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Models\Products;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');

        $query = Products::with('images');

        if ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        }

        $query->orderBy('created_at', 'desc');

        if ($search) {
            $products = $query->get();
            return $this->jsonResponse(true, 'Products retrieved successfully', $products);
        }

        $products = $query->paginate($perPage);
        return $this->jsonResponse(true, 'Products retrieved successfully', $products->items(), [
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'next_page_url' => $products->nextPageUrl(),
            'prev_page_url' => $products->previousPageUrl()
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'gallery' => 'nullable|array',
            'gallery.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'price' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return $this->jsonResponse(false, 'Validation error', null, null, 422, $validator->errors());
        }

        try {
            $data = $request->only(['name', 'description', 'price']);
            $product = new Products($data);

            // Main image handling
            if ($request->hasFile('image')) {
                $product->image = $this->storeImage($request->file('image'), 'products/main');
            }

            $product->save();

            // Gallery images handling
            if ($request->hasFile('gallery')) {
                foreach ($request->file('gallery') as $image) {
                    $path = $this->storeImage($image, 'products/gallery');
                    $product->images()->create(['path' => $path]);
                }
            }
            return $this->jsonResponse(true, 'Product created successfully', $product->load('images'), null, 201);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, 'Error creating product', null, ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $product = Products::with('images')->findOrFail($id);
            return $this->jsonResponse(true, 'Product retrieved successfully', $product);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, 'Product not found', null, ['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'image' => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'gallery' => 'nullable|array',
            'gallery.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'price' => 'sometimes|required|numeric|min:0',
            'deleted_gallery_images' => 'nullable|array',
            'deleted_gallery_images.*' => 'integer'
        ]);

        if ($validator->fails()) {
            return $this->jsonResponse(false, 'Validation error', null, null, 422, $validator->errors());
        }

        try {
            $product = Products::with('images')->findOrFail($id);
            $data = $request->only(['name', 'description', 'price']);

            // Main image update
            if ($request->hasFile('image')) {
                // Delete old image
                if ($product->image) {
                    $this->deleteImage($product->image);
                }
                $data['image'] = $this->storeImage($request->file('image'), 'products/main');
            }

            $product->update($data);

            // Handle gallery images
            if ($request->hasFile('gallery')) {
                foreach ($request->file('gallery') as $image) {
                    $path = $this->storeImage($image, 'products/gallery');
                    $product->images()->create(['path' => $path]);
                }
            }

            // Delete requested gallery images
            if ($request->has('deleted_gallery_images')) {
                $imagesToDelete = $product->images()
                    ->whereIn('id', $request->deleted_gallery_images)
                    ->get();

                foreach ($imagesToDelete as $image) {
                    $this->deleteImage($image->path);
                    $image->delete();
                }
            }
            return $this->jsonResponse(true, 'Product updated successfully', $product->load('images'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, 'Error updating product', null, ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $product = Products::with('images')->findOrFail($id);

            // Delete main image
            if ($product->image) {
                $this->deleteImage($product->image);
            }

            // Delete gallery images
            foreach ($product->images as $image) {
                $this->deleteImage($image->path);
                $image->delete();
            }

            $product->delete();
            return $this->jsonResponse(true, 'Product deleted successfully');
        } catch (\Exception $e) {
            return $this->jsonResponse(false, 'Error deleting product', null, ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Helper method to store images
     */
    private function storeImage($file, $directory): string
    {
        $uniqueName = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs($directory, $uniqueName, 'public');
        return '/storage/' . $path;
    }

    /**
     * Helper method to delete images
     */
    private function deleteImage($path): void
    {
        $relativePath = str_replace('/storage/', '', $path);
        if (Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }
    }

    /**
     * Helper method for JSON responses
     */
    private function jsonResponse(
        bool $status,
        string $message,
        $data = null,
        $pagination = null,
        int $statusCode = 200,
        $errors = null
    ): JsonResponse {
        $response = [
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];

        if ($pagination !== null) {
            $response['pagination'] = $pagination;
        }

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }
}
