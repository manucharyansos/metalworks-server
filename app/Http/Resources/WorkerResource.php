<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkerResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'email'       => $this->email,
            'role_id'     => $this->role_id,
            'role'        => $this->role?->name ?? null,
            'factory_id'  => $this->factory_id,
            'factory'     => $this->factory?->name ?? null,

            'worker' => $this->whenLoaded('worker', function () {
                return [
                    'last_name'    => $this->worker?->last_name,
                    'phone'        => $this->worker?->phone,
                    'second_phone' => $this->worker?->second_phone,
                    'address'      => $this->worker?->address,
                ];
            }),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'display_name' => trim($this->name . ' ' . ($this->worker?->last_name ?? '')),
        ];
    }
}
