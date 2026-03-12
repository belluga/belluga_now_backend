<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'emails' => $this->emails,
            'timezone' => $this->timezone ?? null,
        ];
    }
}
