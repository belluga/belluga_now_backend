<?php

namespace Tests;

use Tests\Helpers\TenantLabels;

abstract class TestCaseTenant extends TestCaseAuthenticated
{
    abstract protected TenantLabels $tenant {
        get;
    }

    protected function normalizeTestUri(string $uri): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        if ($uri === '') {
            return "http://{$this->tenant->subdomain}.{$this->host}/";
        }

        if ($uri[0] !== '/') {
            $uri = "/{$uri}";
        }

        return "http://{$this->tenant->subdomain}.{$this->host}{$uri}";
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['HTTP_HOST'] = "{$this->tenant->subdomain}.{$this->host}";
        $_SERVER['SERVER_NAME'] = "{$this->tenant->subdomain}.{$this->host}";
        $this->withServerVariables([
            'HTTP_HOST' => "{$this->tenant->subdomain}.{$this->host}",
        ]);
    }

    protected string $base_tenant_url {
        get {
            return "http://{$this->tenant->subdomain}.{$this->host}/";
        }
    }

    protected string $base_api_tenant {
        get {
            return "http://{$this->tenant->subdomain}.{$this->host}/api/v1/";
        }
    }

    protected string $base_tenant_api_admin {
        get {
            return "http://{$this->tenant->subdomain}.{$this->host}/admin/api/v1/";
        }
    }
}
