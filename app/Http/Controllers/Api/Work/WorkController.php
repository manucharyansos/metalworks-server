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
use Illuminate\Support\Str;

class WorkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 12);
        $search  = trim((string) $request->input('search', ''));
        $simple  = $request->boolean('simple');
        $locale  = app()->getLocale();

        $q = Work::query()
            ->with(['images'])
            ->where('is_published', true)
            ->when($search !== '', fn($qq) =>
            $qq->where(fn($w) => $w
                ->where("title->$locale", 'like', "%{$search}%")
                ->orWhere("description->$locale", 'like', "%{$search}%")
                ->orWhere("slug->$locale", 'like', "%{$search}%")
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
            'title.hy'       => ['required','string','max:255'],
            'title.ru'       => ['nullable','string','max:255'],
            'title.en'       => ['nullable','string','max:255'],

            'description.hy' => ['nullable','string'],
            'description.ru' => ['nullable','string'],
            'description.en' => ['nullable','string'],

            'slug.hy'        => ['nullable','alpha_dash','max:255'],
            'slug.ru'        => ['nullable','alpha_dash','max:255'],
            'slug.en'        => ['nullable','alpha_dash','max:255'],

            'image'          => ['nullable','image','mimes:jpeg,png,jpg,gif,webp','max:4096'],
            'gallery'        => ['nullable','array'],
            'gallery.*'      => ['image','mimes:jpeg,png,jpg,gif,webp','max:4096'],

            'tags'           => ['nullable','array'],
            'tags.*'         => ['string','max:60'],

            'is_published'   => ['nullable','boolean'],
            'sort_order'     => ['nullable','integer'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'status'=>false,'message'=>'Validation error','errors'=>$v->errors()
            ], 422);
        }

        [$titles, $descs, $slugs] = $this->normalizeTranslations($request);

        if ($err = $this->ensureUniqueSlugs($slugs)) {
            return response()->json(['status'=>false,'message'=>'Validation error','errors'=>$err], 422);
        }

        return DB::transaction(function () use ($request, $titles, $descs, $slugs) {
            $work = new Work();
            $work->setTranslations('title', $titles);
            $work->setTranslations('description', $descs);
            $work->setTranslations('slug', $slugs);

            $work->tags         = $request->input('tags', []);
            $work->is_published = (bool) $request->input('is_published', true);
            $work->sort_order   = $request->input('sort_order');

            if ($request->hasFile('image')) {
                $work->image = $this->storeImage($request->file('image'), 'works/main');
            }
            $work->save();

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
            'title.hy'       => ['sometimes','required','string','max:255'],
            'title.ru'       => ['sometimes','nullable','string','max:255'],
            'title.en'       => ['sometimes','nullable','string','max:255'],

            'description.hy' => ['sometimes','nullable','string'],
            'description.ru' => ['sometimes','nullable','string'],
            'description.en' => ['sometimes','nullable','string'],

            'slug.hy'        => ['sometimes','nullable','alpha_dash','max:255'],
            'slug.ru'        => ['sometimes','nullable','alpha_dash','max:255'],
            'slug.en'        => ['sometimes','nullable','alpha_dash','max:255'],

            'image'          => ['sometimes','image','mimes:jpeg,png,jpg,gif,webp','max:4096'],
            'gallery'        => ['nullable','array'],
            'gallery.*'      => ['image','mimes:jpeg,png,jpg,gif,webp','max:4096'],

            'tags'           => ['nullable','array'],
            'tags.*'         => ['string','max:60'],
            'is_published'   => ['nullable','boolean'],
            'sort_order'     => ['nullable','integer'],

            'deleted_gallery_images'   => ['nullable','array'],
            'deleted_gallery_images.*' => ['integer','exists:work_images,id'],
        ]);

        if ($v->fails()) {
            return response()->json(['status'=>false,'message'=>'Validation error','errors'=>$v->errors()], 422);
        }

        [$titles, $descs, $slugs] = $this->normalizeTranslationsFromExisting(
            (array) $request->input('title', []),
            (array) $request->input('description', []),
            (array) $request->input('slug', []),
            $work->getTranslations('title'),
            $work->getTranslations('description'),
            $work->getTranslations('slug'),
        );

        if ($err = $this->ensureUniqueSlugs($slugs, $work->id)) {
            return response()->json(['status'=>false,'message'=>'Validation error','errors'=>$err], 422);
        }

        return DB::transaction(function () use ($request, $work, $titles, $descs, $slugs) {
            $data = [
                'tags'         => $request->input('tags', $work->tags ?? []),
                'is_published' => $request->input('is_published', $work->is_published),
                'sort_order'   => $request->input('sort_order', $work->sort_order),
            ];

            $work->setTranslations('title', $titles);
            $work->setTranslations('description', $descs);
            $work->setTranslations('slug', $slugs);

            if ($request->hasFile('image')) {
                if ($work->image) $this->deleteImage($work->image);
                $work->image = $this->storeImage($request->file('image'), 'works/main');
            }

            $work->fill($data)->save();

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

    public function showBySlug(Request $request, string $slug): JsonResponse
    {
        $locale = app()->getLocale();
        $work = Work::with('images')->where("slug->$locale", $slug)->firstOrFail();

        return response()->json([
            'status'  => true,
            'message' => 'Work retrieved successfully',
            'data'    => new WorkResource($work),
        ]);
    }


    private function normalizeTranslations(Request $request): array
    {
        $locales = ['hy','ru','en'];
        $titles = (array) $request->input('title', []);
        $descs  = (array) $request->input('description', []);
        $slugs  = (array) $request->input('slug', []);

        $outTitles = []; $outDescs = []; $outSlugs = [];

        foreach ($locales as $loc) {
            $t = $titles[$loc] ?? null;
            $d = $descs[$loc]  ?? null;
            $s = $slugs[$loc]  ?? null;

            if ($t) $outTitles[$loc] = $t;
            if ($d) $outDescs[$loc]  = $d;

            if (!$s && $t) $s = Str::slug($t) ?: Str::random(8);
            if ($s) $outSlugs[$loc] = $s;
        }

        if (!isset($outTitles['hy']) && isset($titles['hy'])) $outTitles['hy'] = $titles['hy'];
        if (!isset($outSlugs['hy']) && isset($titles['hy']))  $outSlugs['hy']  = Str::slug($titles['hy']) ?: Str::random(8);

        return [$outTitles, $outDescs, $outSlugs];
    }

    private function normalizeTranslationsFromExisting(array $inTitles, array $inDescs, array $inSlugs, array $curTitles, array $curDescs, array $curSlugs): array
    {
        $locales = ['hy','ru','en'];

        $titles = $curTitles;
        $descs  = $curDescs;
        $slugs  = $curSlugs;

        foreach ($locales as $loc) {
            if (array_key_exists($loc, $inTitles)) {
                $titles[$loc] = $inTitles[$loc];
                if (!isset($inSlugs[$loc]) && empty($slugs[$loc]) && !empty($inTitles[$loc])) {
                    $slugs[$loc] = Str::slug($inTitles[$loc]) ?: Str::random(8);
                }
            }
            if (array_key_exists($loc, $inDescs)) $descs[$loc] = $inDescs[$loc];
            if (array_key_exists($loc, $inSlugs)) $slugs[$loc] = $inSlugs[$loc];
        }

        if (empty($titles['hy']) && !empty($inTitles['hy'])) $titles['hy'] = $inTitles['hy'];
        if (empty($slugs['hy'])  && !empty($titles['hy']))   $slugs['hy']  = Str::slug($titles['hy']) ?: Str::random(8);

        return [$titles, $descs, $slugs];
    }

    private function ensureUniqueSlugs(array $slugs, ?int $ignoreId = null): ?array
    {
        $errors = [];
        foreach ($slugs as $loc => $val) {
            if (!$val) continue;
            $exists = Work::query()
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->where("slug->$loc", $val)
                ->exists();
            if ($exists) {
                $errors["slug.$loc"] = "Slug ($loc) արդեն զբաղված է";
            }
        }
        return $errors ?: null;
    }

    private function storeImage($file, $dir): string {
        $name = uniqid().'_'.preg_replace('/\s+/', '_', $file->getClientOriginalName());
        return $file->storeAs($dir, $name, 'public');
    }

    private function deleteImage($path): void {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
