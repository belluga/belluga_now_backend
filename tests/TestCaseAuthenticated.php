<?php

namespace Tests;

use Tests\Enums\AccountEnvironments;
use Tests\Enums\TestVariableLabels;

abstract class TestCaseAuthenticated extends TestCase
{
    protected string $main_account_token {
        get {
            return $this->getGlobal(TestVariableLabels::LANDLORD_TOKEN->value);
        }
    }

    protected string $secondary_account_token {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_TOKEN->value);
        }
    }

    protected function getHeaders(AccountEnvironments $accountEnv = AccountEnvironments::MAIN): array {

        $token = match ($accountEnv) {
            AccountEnvironments::MAIN => $this->main_account_token,
            AccountEnvironments::SECONDARY => $this->secondary_account_token,
        };

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }
}
