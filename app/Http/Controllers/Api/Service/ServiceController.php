<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $query = Service::query()->where('is_active', true)->orderBy('sort')->orderByDesc('created_at');

        if ($request->boolean('simple')) {
            $items = $query->get();
            return $this->jsonResponse(true, 'Services retrieved', $items);
        }

        $p = $query->paginate($perPage);
        return $this->jsonResponse(true, 'Services retrieved', $p->items(), [
            'current_page' => $p->currentPage(),
            'last_page'    => $p->lastPage(),
            'per_page'     => $p->perPage(),
            'total'        => $p->total(),
            'next_page_url'=> $p->nextPageUrl(),
            'prev_page_url'=> $p->previousPageUrl(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'slug'        => 'nullable|string|max:255|unique:services,slug',
            'description' => 'nullable|string',
            'image'       => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'sort'        => 'nullable|integer|min:0',
            'is_active'   => 'boolean',
        ]);
        if ($v->fails()) return $this->jsonResponse(false, 'Validation error', null, null, 422, $v->errors());

        $data = $request->only(['title','slug','description','sort','is_active']);
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']) ?: Str::random(8);
        }

        if ($request->hasFile('image')) {
            $data['image'] = $this->storeImage($request->file('image'), 'services');
        }

        $service = Service::create($data);
        return $this->jsonResponse(true, 'Service created', $service, null, 201);
    }

    public function show(Service $service): JsonResponse
    {
        return $this->jsonResponse(true, 'Service retrieved', $service);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'title'       => 'sometimes|required|string|max:255',
            'slug'        => 'sometimes|nullable|string|max:255|unique:services,slug,'.$service->id,
            'description' => 'sometimes|nullable|string',
            'image'       => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'sort'        => 'sometimes|integer|min:0',
            'is_active'   => 'sometimes|boolean',
        ]);
        if ($v->fails()) return $this->jsonResponse(false, 'Validation error', null, null, 422, $v->errors());

        $data = $request->only(['title','slug','description','sort','is_active']);

        if ($request->hasFile('image')) {
            if ($service->image) $this->deleteImage($service->image);
            $data['image'] = $this->storeImage($request->file('image'), 'services');
        }

        $service->update($data);
        return $this->jsonResponse(true, 'Service updated', $service);
    }

    public function destroy(Service $service): JsonResponse
    {
        if ($service->image) $this->deleteImage($service->image);
        $service->delete();
        return $this->jsonResponse(true, 'Service deleted');
    }

    private function storeImage($file, $dir): string {
        $name = uniqid().'_'.$file->getClientOriginalName();
        return $file->storeAs($dir, $name, 'public');
    }
    private function deleteImage($path): void {
        if ($path && Storage::disk('public')->exists($path)) Storage::disk('public')->delete($path);
    }
    private function jsonResponse(bool $status, string $message, $data = null, $pagination = null, int $code = 200, $errors = null): JsonResponse {
        $res = ['status'=>$status,'message'=>$message,'data'=>$data];
        if ($pagination !== null) $res['pagination'] = $pagination;
        if ($errors !== null) $res['errors'] = $errors;
        return response()->json($res, $code);
    }
}
