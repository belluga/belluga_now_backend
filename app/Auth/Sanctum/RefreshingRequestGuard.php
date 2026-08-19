<?php

declare(strict_types=1);

namespace App\Auth\Sanctum;

use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;

final class RefreshingRequestGuard extends RequestGuard
{
    public function setRequest(Request $request): static
    {
        $this->forgetUser();

        return parent::setRequest($request);
    }
}
