<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'client' => [
                'id' => $this->user->id ?? null,
                'name' => $this->user->name ?? null,
                'email' => $this->user->email ?? null,
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
