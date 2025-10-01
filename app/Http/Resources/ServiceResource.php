<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ServiceResource extends JsonResource
{
    public function toArray($request)
    {
        $loc = app()->getLocale();
        $fallback = function(array $arr) use ($loc) {
            return $arr[$loc] ?? $arr['hy'] ?? $arr['ru'] ?? $arr['en'] ?? null;
        };

        $titleAll = $this->getTranslations('title') ?? [];
        $descAll  = $this->getTranslations('description') ?? [];
        $slugAll  = $this->getTranslations('slug') ?? [];

        return [
            'id'          => $this->id,
            'title'       => $fallback($titleAll),
            'title_all'   => $titleAll,
            'description' => $fallback($descAll),
            'description_all' => $descAll,
            'slug'        => $fallback($slugAll),
            'slug_all'    => $slugAll,

            'price_from'  => $this->price_from,
            'lead_time'   => $this->lead_time,
            'lead_time_days' => $this->lead_time_days,
            'is_new'      => (bool) ($this->is_new ?? false),

            'image_url'        => $this->image ? Storage::disk('public')->url($this->image) : null,
            'video_url'        => $this->video ? Storage::disk('public')->url($this->video) : null,
            'video_poster_url' => $this->video_poster ? Storage::disk('public')->url($this->video_poster) : null,

        ];
    }
}
