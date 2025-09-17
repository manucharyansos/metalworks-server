<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    /** List */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $locale  = app()->getLocale();
        $search  = trim((string) $request->input('search', ''));

        $query = Service::query()->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search, $locale) {
                $q->where("title->$locale", 'like', "%{$search}%")
                    ->orWhere("description->$locale", 'like', "%{$search}%")
                    ->orWhere("slug->$locale", 'like', "%{$search}%");
            });
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

    /** Create */
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
            'video'          => ['nullable','mimetypes:video/mp4,video/webm,video/ogg,video/quicktime','max:51200'],
            'video_poster'   => ['nullable','image','mimes:jpeg,png,jpg,webp','max:4096'],
        ]);
        if ($v->fails()) {
            return $this->jsonResponse(false, 'Validation error', null, null, 422, $v->errors());
        }

        [$titles, $descriptions, $slugs] = $this->normalizeTranslations($request);

        if ($err = $this->ensureUniqueSlugs($slugs)) {
            return $this->jsonResponse(false, 'Validation error', null, null, 422, $err);
        }

        $service = new Service();
        $service->setTranslations('title', $titles);
        $service->setTranslations('description', $descriptions);
        $service->setTranslations('slug', $slugs);

        if ($request->hasFile('image')) {
            $service->image = $this->storeFile($request->file('image'), 'services');
        }
        if ($request->hasFile('video')) {
            $service->video = $this->storeFile($request->file('video'), 'services/videos');
        }
        if ($request->hasFile('video_poster')) {
            $service->video_poster = $this->storeFile($request->file('video_poster'), 'services/videos');
        }

        $service->save();

        return $this->jsonResponse(true, 'Service created', new ServiceResource($service), null, 201);
    }

    /** Show by ID (apiResource) */
    public function show(Service $service): JsonResponse
    {
        return $this->jsonResponse(true, 'Service retrieved', new ServiceResource($service));
    }

    /** Update */
    public function update(Request $request, Service $service): JsonResponse
    {
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
            'video'          => ['sometimes','mimetypes:video/mp4,video/webm,video/ogg,video/quicktime','max:51200'],
            'video_poster'   => ['sometimes','image','mimes:jpeg,png,jpg,webp','max:4096'],
        ]);
        if ($v->fails()) {
            return $this->jsonResponse(false, 'Validation error', null, null, 422, $v->errors());
        }

        $incomingTitles = (array) $request->input('title', []);
        $incomingDescs  = (array) $request->input('description', []);
        $incomingSlugs  = (array) $request->input('slug', []);

        [$titles, $descriptions, $slugs] = $this->normalizeTranslationsFromExisting(
            $incomingTitles,
            $incomingDescs,
            $incomingSlugs,
            $service->getTranslations('title'),
            $service->getTranslations('description'),
            $service->getTranslations('slug')
        );

        if ($err = $this->ensureUniqueSlugs($slugs, $service->id)) {
            return $this->jsonResponse(false, 'Validation error', null, null, 422, $err);
        }

        $service->setTranslations('title', $titles);
        $service->setTranslations('description', $descriptions);
        $service->setTranslations('slug', $slugs);

        // files
        if ($request->hasFile('image')) {
            if ($service->image) $this->deleteFile($service->image);
            $service->image = $this->storeFile($request->file('image'), 'services');
        }
        if ($request->hasFile('video')) {
            if ($service->video) $this->deleteFile($service->video);
            $service->video = $this->storeFile($request->file('video'), 'services/videos');
        }
        if ($request->hasFile('video_poster')) {
            if ($service->video_poster) $this->deleteFile($service->video_poster);
            $service->video_poster = $this->storeFile($request->file('video_poster'), 'services/videos');
        }

        $service->save();

        return $this->jsonResponse(true, 'Service updated', new ServiceResource($service));
    }

    /** Delete */
    public function destroy(Service $service): JsonResponse
    {
        foreach (['image','video','video_poster'] as $field) {
            if ($service->$field) $this->deleteFile($service->$field);
        }
        $service->delete();
        return $this->jsonResponse(true, 'Service deleted');
    }

    /** OPTIONAL: Show by current-locale slug (good for SEO URLs) */
    public function showBySlug(Request $request, string $slug): JsonResponse
    {
        $locale = app()->getLocale();
        $service = Service::where("slug->$locale", $slug)->firstOrFail();
        return $this->jsonResponse(true, 'Service retrieved', new ServiceResource($service));
    }

    /* ----------------- Helpers ----------------- */

    /** Normalize translations for store(): auto slug from title if missing */
    private function normalizeTranslations(Request $request): array
    {
        $locales = ['hy','ru','en'];

        $titles = (array) $request->input('title', []);
        $descs  = (array) $request->input('description', []);
        $slugs  = (array) $request->input('slug', []);

        $outTitles = [];
        $outDescs  = [];
        $outSlugs  = [];

        foreach ($locales as $loc) {
            $t = $titles[$loc] ?? null;
            $d = $descs[$loc]  ?? null;
            $s = $slugs[$loc]  ?? null;

            if ($t) $outTitles[$loc] = $t;
            if ($d) $outDescs[$loc]  = $d;

            // auto-slug if missing but title exists
            if (!$s && $t) $s = Str::slug($t) ?: Str::random(8);
            if ($s) $outSlugs[$loc] = $s;
        }

        if (!isset($outTitles['hy']) && isset($titles['hy'])) $outTitles['hy'] = $titles['hy'];
        if (!isset($outSlugs['hy']) && isset($titles['hy']))   $outSlugs['hy']  = Str::slug($titles['hy']) ?: Str::random(8);

        return [$outTitles, $outDescs, $outSlugs];
    }

    /** Normalize for update(): merge with existing, auto-slug when adding new title without slug */
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
            if (array_key_exists($loc, $inDescs))  $descs[$loc] = $inDescs[$loc];
            if (array_key_exists($loc, $inSlugs))  $slugs[$loc] = $inSlugs[$loc];
        }

        if (empty($titles['hy']) && !empty($inTitles['hy'])) $titles['hy'] = $inTitles['hy'];
        if (empty($slugs['hy'])  && !empty($titles['hy']))   $slugs['hy']  = Str::slug($titles['hy']) ?: Str::random(8);

        return [$titles, $descs, $slugs];
    }

    /** Ensure per-locale slug uniqueness (DB-level alternative if no generated columns) */
    private function ensureUniqueSlugs(array $slugs, ?int $ignoreId = null): ?array
    {
        $errors = [];
        foreach ($slugs as $loc => $val) {
            if (!$val) continue;
            $exists = Service::query()
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->where("slug->$loc", $val)
                ->exists();
            if ($exists) {
                $errors["slug.$loc"] = "Slug ($loc) արդեն զբաղված է";
            }
        }
        return $errors ? $errors : null;
    }

    private function storeFile($file, $dir): string
    {
        $clean = preg_replace('/\s+/', '_', $file->getClientOriginalName());
        $name  = uniqid().'_'.$clean;
        return $file->storeAs($dir, $name, 'public');
    }

    private function deleteFile($path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function jsonResponse(bool $status, string $message, $data = null, $pagination = null, int $code = 200, $errors = null): JsonResponse
    {
        $res = ['status'=>$status,'message'=>$message,'data'=>$data];
        if ($pagination !== null) $res['pagination'] = $pagination;
        if ($errors !== null)     $res['errors']     = $errors;
        return response()->json($res, $code);
    }
}
