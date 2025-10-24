<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'name'        => (string) $this->name,
            'description' => (string) $this->description,
            'price'       => (float) ($this->price ?? 0),
            'image_url'   => $this->image ? Storage::disk('public')->url($this->image) : null,
            // only if loaded
            'images'      => ProductImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
