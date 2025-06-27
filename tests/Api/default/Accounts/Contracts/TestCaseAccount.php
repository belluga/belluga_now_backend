<?php

namespace Tests\Api\default\Accounts\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\RoleLabels;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseAuthenticated;

abstract class TestCaseAccount extends TestCaseAuthenticated {
    abstract protected TenantLabels $tenant {
        get;
    }

    abstract protected AccountLabels $account {
        get;
    }

    protected string $base_api {
        get {
            return "http://{$this->tenant->subdomain}.localhost/api/accounts/{$this->account->slug}/";
        }
    }
}
