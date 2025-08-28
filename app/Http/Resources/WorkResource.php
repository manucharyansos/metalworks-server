<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

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
            'image_url'   => $this->image_url,
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
