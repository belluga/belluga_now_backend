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
            return "{$this->base_api_tenant}accounts/{$this->account->slug}/";
        }
    }
}
