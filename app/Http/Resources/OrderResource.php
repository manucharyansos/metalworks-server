<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'client_id'     => $this->client_id,
            'name'          => $this->name,
            'description'   => $this->description,
            'status'        => $this->status,
            'creator_id'    => $this->creator_id,

            // հիմնական դաշտերը
            'finish_date'   => optional($this->dates)->finish_date,
            'order_number'  => optional($this->orderNumber)->number,
            'prefix_code'   => optional($this->prefixCode)->code,

            // client — օգտագործում ենք ՔՈ ClientResource–ը
            'client'        => new ClientResource($this->whenLoaded('client')),

            // order user (պատվերի user)
            'user' => $this->whenLoaded('user', fn () => [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),

            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
