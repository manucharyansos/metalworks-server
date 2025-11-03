<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name ?? $this->name,
            'email' => $this->user->email ?? null,
            'client' => [
                'phone' => $this->phone,
                'second_phone' => $this->second_phone,
                'address' => $this->address,
                'company_name' => $this->company_name,
                'last_name' => $this->last_name,
                'type' => $this->type,
            ]
        ];
    }
}
