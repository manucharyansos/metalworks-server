<?php

namespace App\Http\Controllers\Api\Work;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkResource;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class WorkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 12);
        $search  = $request->string('search')->toString();
        $simple  = filter_var($request->input('simple', false), FILTER_VALIDATE_BOOLEAN);

        $q = Work::query()
            ->with(['images'])
            ->where('is_published', true)
            ->when($search, fn($qq) =>
            $qq->where(fn($w) =>
            $w->where('title','like',"%{$search}%")
                ->orWhere('description','like',"%{$search}%")
            )
            )
            ->orderByRaw('COALESCE(sort_order, 999999) asc')
            ->orderByDesc('created_at');

        if ($simple) {
            $items = $q->get();
            return response()->json([
                'status'  => true,
                'message' => 'Works retrieved successfully',
                'data'    => WorkResource::collection($items),
            ]);
        }

        $p = $q->paginate($perPage);
        return response()->json([
            'status'     => true,
            'message'    => 'Works retrieved successfully',
            'data'       => WorkResource::collection($p->items()),
            'pagination' => [
                'current_page'  => $p->currentPage(),
                'last_page'     => $p->lastPage(),
                'per_page'      => $p->perPage(),
                'total'         => $p->total(),
                'next_page_url' => $p->nextPageUrl(),
                'prev_page_url' => $p->previousPageUrl(),
            ],
        ]);
    }

    public function show($id): JsonResponse
    {
        $work = Work::with(['images'])->findOrFail($id);
        return response()->json([
            'status'  => true,
            'message' => 'Work retrieved successfully',
            'data'    => new WorkResource($work),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'slug'        => 'required|string|max:255|unique:works,slug',
            'description' => 'nullable|string',
            'image'       => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'gallery'     => 'nullable|array',
            'gallery.*'   => 'image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'tags'        => 'nullable|array',
            'tags.*'      => 'string|max:60',
            'is_published'=> 'nullable|boolean',
            'sort_order'  => 'nullable|integer',
        ]);

        if ($v->fails()) {
            return response()->json(['status'=>false,'message'=>'Validation error','errors'=>$v->errors()], 422);
        }

        return DB::transaction(function () use ($request) {
            $data = $request->only(['title','slug','description','tags','is_published','sort_order']);
            if ($request->hasFile('image')) {
                $data['image'] = $this->storeImage($request->file('image'), 'works/main');
            }
            $work = Work::create($data);

            if ($request->hasFile('gallery')) {
                foreach ($request->file('gallery') as $img) {
                    $path = $this->storeImage($img, 'works/gallery');
                    $work->images()->create(['path' => $path]);
                }
            }

            $work->load(['images']);
            return response()->json([
                'status'  => true,
                'message' => 'Work created successfully',
                'data'    => new WorkResource($work),
            ], 201);
        });
    }

    public function update(Request $request, $id): JsonResponse
    {
        $work = Work::with(['images'])->findOrFail($id);

        $v = Validator::make($request->all(), [
            'title'       => 'sometimes|nullable|string|max:255',
            'slug'        => 'sometimes|nullable|string|max:255|unique:works,slug,'.$work->id,
            'description' => 'sometimes|nullable|string',
            'image'       => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'gallery'     => 'nullable|array',
            'gallery.*'   => 'image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'tags'        => 'nullable|array',
            'tags.*'      => 'string|max:60',
            'is_published'=> 'nullable|boolean',
            'sort_order'  => 'nullable|integer',
            'deleted_gallery_images'   => 'nullable|array',
            'deleted_gallery_images.*' => 'integer|exists:work_images,id',
        ]);

        if ($v->fails()) {
            return response()->json(['status'=>false,'message'=>'Validation error','errors'=>$v->errors()], 422);
        }

        return DB::transaction(function () use ($request, $work) {
            $data = $request->only(['title','slug','description','tags','is_published','sort_order']);

            if ($request->hasFile('image')) {
                if ($work->image) $this->deleteImage($work->image);
                $data['image'] = $this->storeImage($request->file('image'), 'works/main');
            }
            $work->update($data);

            if ($request->hasFile('gallery')) {
                foreach ($request->file('gallery') as $img) {
                    $path = $this->storeImage($img, 'works/gallery');
                    $work->images()->create(['path' => $path]);
                }
            }

            if ($request->filled('deleted_gallery_images')) {
                $toDelete = $work->images()->whereIn('id', $request->deleted_gallery_images)->get();
                foreach ($toDelete as $img) {
                    $this->deleteImage($img->path);
                    $img->delete();
                }
            }

            $work->load(['images']);
            return response()->json([
                'status'  => true,
                'message' => 'Work updated successfully',
                'data'    => new WorkResource($work),
            ]);
        });
    }

    public function destroy($id): JsonResponse
    {
        $work = Work::with('images')->findOrFail($id);
        if ($work->image) $this->deleteImage($work->image);
        foreach ($work->images as $img) {
            $this->deleteImage($img->path);
        }
        $work->delete();
        return response()->json(['status'=>true,'message'=>'Work deleted successfully']);
    }

    private function storeImage($file, $dir): string {
        $name = uniqid().'_'.$file->getClientOriginalName();
        return $file->storeAs($dir, $name, 'public');
    }

    private function deleteImage($path): void {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
