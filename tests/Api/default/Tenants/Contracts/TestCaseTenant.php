<?php

namespace Tests\Api\default\Tenants\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\RoleLabels;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseAuthenticated;

abstract class TestCaseTenant extends TestCaseAuthenticated {
    abstract protected TenantLabels $tenant {
        get;
    }

    protected string $base_api {
        get {
            return "http://{$this->tenant->subdomain}.localhost/api/";
        }
    }
}
