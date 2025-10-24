<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductImageResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'  => $this->id,
            'url' => $this->path ? Storage::disk('public')->url($this->path) : null,
        ];
    }
}
