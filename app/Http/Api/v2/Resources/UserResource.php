<?php

declare(strict_types=1);

namespace App\Http\Api\v2\Resources;

use App\Http\Api\v1\Resources\UserResource as V1;

class UserResource extends V1
{
    public function toArray($request): array
    {
        return parent::current($request);
    }
}
