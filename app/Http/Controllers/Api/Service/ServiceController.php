<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
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
        $query = Service::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderByDesc('created_at');

        if ($request->boolean('with_works')) {
            $query->with('works');
        }

        if ($request->boolean('simple')) {
            $items = $query->get();
            return $this->jsonResponse(true, 'Services retrieved', ServiceResource::collection($items));
        }

        $p = $query->paginate($perPage);
        return $this->jsonResponse(true, 'Services retrieved', ServiceResource::collection($p->items()), [
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
            'title'         => 'required|string|max:255',
            'slug'          => 'nullable|string|max:255|unique:services,slug',
            'description'   => 'nullable|string',
            'image'         => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'video'         => 'nullable|mimetypes:video/mp4,video/webm,video/ogg,video/quicktime|max:51200', // 50 MB
            'video_poster'  => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'sort'          => 'nullable|integer|min:0',
            'is_active'     => 'boolean',
        ]);
        if ($v->fails()) return $this->jsonResponse(false, 'Validation error', null, null, 422, $v->errors());

        $data = $request->only(['title','slug','description','sort','is_active']);
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']) ?: Str::random(8);
        }

        if ($request->hasFile('image')) {
            $data['image'] = $this->storeFile($request->file('image'), 'services');
        }
        if ($request->hasFile('video')) {
            $data['video'] = $this->storeFile($request->file('video'), 'services/videos');
        }
        if ($request->hasFile('video_poster')) {
            $data['video_poster'] = $this->storeFile($request->file('video_poster'), 'services/videos');
        }

        $service = Service::create($data)->loadMissing('works');
        return $this->jsonResponse(true, 'Service created', new ServiceResource($service), null, 201);
    }

    public function show(Service $service): JsonResponse
    {
        $service->load('works');
        return $this->jsonResponse(true, 'Service retrieved', new ServiceResource($service));
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'title'         => 'sometimes|required|string|max:255',
            'slug'          => 'sometimes|nullable|string|max:255|unique:services,slug,'.$service->id,
            'description'   => 'sometimes|nullable|string',
            'image'         => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'video'         => 'sometimes|mimetypes:video/mp4,video/webm,video/ogg,video/quicktime|max:51200',
            'video_poster'  => 'sometimes|image|mimes:jpeg,png,jpg,webp|max:4096',
            'sort'          => 'sometimes|integer|min:0',
            'is_active'     => 'sometimes|boolean',
        ]);
        if ($v->fails()) return $this->jsonResponse(false, 'Validation error', null, null, 422, $v->errors());

        $data = $request->only(['title','slug','description','sort','is_active']);

        if ($request->hasFile('image')) {
            if ($service->image) $this->deleteFile($service->image);
            $data['image'] = $this->storeFile($request->file('image'), 'services');
        }
        if ($request->hasFile('video')) {
            if ($service->video) $this->deleteFile($service->video);
            $data['video'] = $this->storeFile($request->file('video'), 'services/videos');
        }
        if ($request->hasFile('video_poster')) {
            if ($service->video_poster) $this->deleteFile($service->video_poster);
            $data['video_poster'] = $this->storeFile($request->file('video_poster'), 'services/videos');
        }

        $service->update($data);
        $service->load('works');

        return $this->jsonResponse(true, 'Service updated', new ServiceResource($service));
    }

    public function destroy(Service $service): JsonResponse
    {
        foreach (['image','video','video_poster'] as $field) {
            if ($service->$field) $this->deleteFile($service->$field);
        }
        $service->delete();
        return $this->jsonResponse(true, 'Service deleted');
    }

    private function storeFile($file, $dir): string {
        $clean = preg_replace('/\s+/', '_', $file->getClientOriginalName());
        $name = uniqid().'_'.$clean;
        return $file->storeAs($dir, $name, 'public');
    }
    private function deleteFile($path): void {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
    private function jsonResponse(bool $status, string $message, $data = null, $pagination = null, int $code = 200, $errors = null): JsonResponse {
        $res = ['status'=>$status,'message'=>$message,'data'=>$data];
        if ($pagination !== null) $res['pagination'] = $pagination;
        if ($errors !== null) $res['errors'] = $errors;
        return response()->json($res, $code);
    }
}
