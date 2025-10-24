<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Models\Products;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ProductResource;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $search  = $request->input('search');

        $query = Products::with('images')->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
            $items = $query->get();
            return $this->jsonResponse(true, 'Products retrieved successfully',
                ProductResource::collection($items)
            );
        }

        $products = $query->paginate($perPage);

        return $this->jsonResponse(
            true,
            'Products retrieved successfully',
            ProductResource::collection($products->items()),
            [
                'current_page'  => $products->currentPage(),
                'last_page'     => $products->lastPage(),
                'per_page'      => $products->perPage(),
                'total'         => $products->total(),
                'next_page_url' => $products->nextPageUrl(),
                'prev_page_url' => $products->previousPageUrl(),
            ]
        );
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'description' => 'required|string',
            'image'       => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'gallery'     => 'nullable|array',
            'gallery.*'   => 'image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'price'       => 'required|numeric|min:0',
        ]);
        if ($v->fails()) {
            return $this->jsonResponse(false, 'Validation error', null, null, 422, $v->errors());
        }

        try {
            $product = DB::transaction(function () use ($request) {
                $data = $request->only(['name','description','price']);
                $p = new Products($data);

                if ($request->hasFile('image')) {
                    $p->image = $this->storeImage($request->file('image'), 'products/main');
                }
                $p->save();

                if ($request->hasFile('gallery')) {
                    foreach ($request->file('gallery') as $image) {
                        $path = $this->storeImage($image, 'products/gallery');
                        $p->images()->create(['path' => $path]);
                    }
                }
                return $p->load('images');
            });

            return $this->jsonResponse(true, 'Product created successfully',
                new ProductResource($product), null, 201
            );
        } catch (\Exception $e) {
            return $this->jsonResponse(false, 'Error creating product', null, null, 500, ['error' => $e->getMessage()]);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $product = Products::with('images')->findOrFail($id);
            return $this->jsonResponse(true, 'Product retrieved successfully',
                new ProductResource($product)
            );
        } catch (\Exception $e) {
            return $this->jsonResponse(false, 'Product not found', null, null, 404, ['error' => $e->getMessage()]);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name'                    => 'sometimes|required|string|max:255',
            'description'             => 'sometimes|required|string',
            'image'                   => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'gallery'                 => 'nullable|array',
            'gallery.*'               => 'image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'price'                   => 'sometimes|required|numeric|min:0',
            'deleted_gallery_images'  => 'nullable|array',
            'deleted_gallery_images.*'=> 'integer',
        ]);
        if ($v->fails()) {
            return $this->jsonResponse(false, 'Validation error', null, null, 422, $v->errors());
        }

        try {
            $product = DB::transaction(function () use ($request, $id) {
                $p = Products::with('images')->findOrFail($id);
                $data = $request->only(['name','description','price']);

                if ($request->hasFile('image')) {
                    if ($p->image) $this->deleteImage($p->image);
                    $data['image'] = $this->storeImage($request->file('image'), 'products/main');
                }
                $p->update($data);

                if ($request->hasFile('gallery')) {
                    foreach ($request->file('gallery') as $image) {
                        $path = $this->storeImage($image, 'products/gallery');
                        $p->images()->create(['path' => $path]);
                    }
                }

                if ($request->filled('deleted_gallery_images')) {
                    $imagesToDelete = $p->images()->whereIn('id', $request->deleted_gallery_images)->get();
                    foreach ($imagesToDelete as $img) {
                        $this->deleteImage($img->path);
                        $img->delete();
                    }
                }

                return $p->load('images');
            });

            return $this->jsonResponse(true, 'Product updated successfully',
                new ProductResource($product)
            );
        } catch (\Exception $e) {
            return $this->jsonResponse(false, 'Error updating product', null, null, 500, ['error' => $e->getMessage()]);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id) {
                $p = Products::with('images')->findOrFail($id);

                if ($p->image) $this->deleteImage($p->image);
                foreach ($p->images as $img) {
                    $this->deleteImage($img->path);
                    $img->delete();
                }
                $p->delete();
            });

            return $this->jsonResponse(true, 'Product deleted successfully');
        } catch (\Exception $e) {
            return $this->jsonResponse(false, 'Error deleting product', null, null, 500, ['error' => $e->getMessage()]);
        }
    }

    private function storeImage($file, $directory): string
    {
        $uniqueName = uniqid() . '_' . $file->getClientOriginalName();
        return $file->storeAs($directory, $uniqueName, 'public');
    }

    private function deleteImage($storedPath): void
    {
        if ($storedPath && Storage::disk('public')->exists($storedPath)) {
            Storage::disk('public')->delete($storedPath);
        }
    }

    private function jsonResponse(
        bool $status,
        string $message,
        $data = null,
        $pagination = null,
        int $statusCode = 200,
        $errors = null
    ): JsonResponse {
        $response = ['status'=>$status, 'message'=>$message, 'data'=>$data];
        if ($pagination !== null) $response['pagination'] = $pagination;
        if ($errors !== null) $response['errors'] = $errors;
        return response()->json($response, $statusCode);
    }
}
