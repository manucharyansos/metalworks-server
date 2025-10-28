<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkerResource extends JsonResource
{
    public function toArray($request)
    {
        $client = $this->client;

        return [
            'id'        => $this->id,
            'name'      => (string) $this->name,
            'email'     => (string) $this->email,

            'role'      => $this->relationLoaded('role') && $this->role
                ? [
                    'id'   => $this->role->id,
                    'name' => $this->role->name,
                ]
                : null,

            'client'    => $client ? [
                'type'          => (string) $client->type,          // worker | physPerson | legalEntity
                'last_name'     => (string) ($client->last_name ?? ''),
                'phone'         => (string) $client->phone,
                'second_phone'  => (string) ($client->second_phone ?? ''),
                'address'       => (string) ($client->address ?? ''),
            ] : null,

            'created_at'=> optional($this->created_at)->toIso8601String(),
            'updated_at'=> optional($this->updated_at)->toIso8601String(),
        ];
    }
}
