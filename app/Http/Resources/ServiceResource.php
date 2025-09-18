<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'title'         => $this->title,
            'slug'          => $this->slug,
            'description'   => $this->description,
            'image'         => $this->image,
            'image_url'     => $this->image_url,
            'video'         => $this->video,
            'video_url'     => $this->video_url,
            'video_poster'  => $this->video_poster,
            'video_poster_url' => $this->video_poster_url,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,

            'translations'  => $this->when(
                $request->boolean('full'),
                [
                    'title'       => $this->getTranslations('title'),
                    'slug'        => $this->getTranslations('slug'),
                    'description' => $this->getTranslations('description'),
                ]
            ),
        ];
    }
}
