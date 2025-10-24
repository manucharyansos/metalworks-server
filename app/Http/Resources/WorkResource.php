<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class WorkResource extends JsonResource
{
    public function toArray($request): array
    {
        $loc = app()->getLocale();
        $fallback = function(array $arr) use ($loc) {
            return $arr[$loc] ?? $arr['hy'] ?? $arr['ru'] ?? $arr['en'] ?? null;
        };

        $titleAll = $this->getTranslations('title') ?? [];
        $descAll  = $this->getTranslations('description') ?? [];
        $slugAll  = $this->getTranslations('slug') ?? [];

        return [
            'id'              => $this->id,

            'title'           => $fallback($titleAll),
            'description'     => $fallback($descAll),
            'slug'            => $fallback($slugAll),

            'title_all'       => $titleAll,
            'description_all' => $descAll,
            'slug_all'        => $slugAll,

            'tags'         => $this->tags ?? [],
            'is_published' => (bool) $this->is_published,
            'sort_order'   => $this->sort_order,

            'image_url'   => $this->image ? Storage::disk('public')->url($this->image) : null,

            'images' => $this->whenLoaded('images', function () {
                return $this->images->map(function ($img) {
                    return [
                        'id'  => $img->id,
                        'url' => $img->path ? Storage::disk('public')->url($img->path) : null,
                    ];
                });
            }),

            'created_at'  => $this->created_at,
        ];
    }
}
