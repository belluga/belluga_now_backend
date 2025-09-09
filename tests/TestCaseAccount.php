<?php

namespace Tests;

use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

abstract class TestCaseAccount extends TestCaseTenant {

    abstract protected AccountLabels $account {
        get;
    }

    protected string $base_api_account {
        get {
            return "http://{$this->tenant->subdomain}.".env('APP_HOST')."/api/accounts/{$this->account->slug}/";
        }
    }
}
