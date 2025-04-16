<?php

namespace App\Http\Api\v1\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource {
    public function toArray($request): array {
        return [
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
