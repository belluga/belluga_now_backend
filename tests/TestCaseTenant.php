<?php

namespace Tests;

use Tests\Helpers\TenantLabels;

abstract class TestCaseTenant extends TestCaseAuthenticated {
    abstract protected TenantLabels $tenant {
        get;
    }

    protected string $base_api_tenant {
        get {
            return "http://{$this->tenant->subdomain}.localhost/api/";
        }
    }
}
