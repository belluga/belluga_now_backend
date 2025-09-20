<?php

namespace Tests;

use Tests\Helpers\TenantLabels;

abstract class TestCaseTenant extends TestCaseAuthenticated {
    abstract protected TenantLabels $tenant {
        get;
    }

    protected string $base_api_tenant {
        get {
            return "http://{$this->tenant->subdomain}.{$this->host}/api/";
        }
    }

    protected string $base_tenant_api_admin {
        get {
            return "http://{$this->tenant->subdomain}.{$this->host}/admin/api/";
        }
    }
}
