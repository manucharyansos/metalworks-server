<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class WorkResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'slug'        => $this->slug,
            'description' => $this->description,
            'tags'        => $this->tags ?? [],
            'is_published'=> (bool) $this->is_published,
            'sort_order'  => $this->sort_order,
            'image_url'   => $this->image ? Storage::disk('public')->url($this->image) : null,
            'images'      => $this->whenLoaded('images', fn() =>
            $this->images->map(fn($img) => [
                'id'  => $img->id,
                'url' => $img->url,
            ])
            ),
            'created_at'  => $this->created_at,
        ];
    }
}
