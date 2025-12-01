<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'type'          => $this->type,
            'name'          => $this->name,
            'phone'         => $this->phone,
            'address'       => $this->address,
            'last_name'     => $this->last_name,
            'second_phone'  => $this->second_phone,
            'company_name'  => $this->company_name,
            'AVC'           => $this->AVC,
            'accountant'    => $this->accountant,

            'user' => $this->whenLoaded('user', fn() => [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),

            'display_name' => $this->type === 'legalEntity'
                ? ($this->company_name ?: $this->name)
                : trim($this->name . ' ' . ($this->last_name ?? '')),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
