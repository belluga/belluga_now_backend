<?php

declare(strict_types=1);

namespace App\Auth\Sanctum;

use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final class RefreshingRequestGuard extends RequestGuard
{
    private bool $hasExplicitUser = false;

    public function setUser(Authenticatable $user): static
    {
        $this->hasExplicitUser = true;

        parent::setUser($user);

        return $this;
    }

    public function forgetUser(): static
    {
        $this->hasExplicitUser = false;

        parent::forgetUser();

        return $this;
    }

    public function setRequest(Request $request): static
    {
        if (! $this->hasExplicitUser) {
            $this->forgetUser();
        }

        return parent::setRequest($request);
    }
}
