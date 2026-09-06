<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Models\Landlord\Tenant;

trait RestoresTenantContextAfterRequest
{
    public function call(
        $method,
        $uri,
        $parameters = [],
        $cookies = [],
        $files = [],
        $server = [],
        $content = null,
    ) {
        $tenant = Tenant::current();
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $tenant?->makeCurrent();

        return $response;
    }
}
