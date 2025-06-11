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

    protected string $account_user_admin_token_device_1 {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_DEVICE_1_TOKEN->value, $value);
            $this->account_user_admin_token_device_1 = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_DEVICE_1_TOKEN->value);
        }
    }

    protected function getHeaders(AccountEnvironments $accountEnv = AccountEnvironments::MAIN): array {

        $token = match ($accountEnv) {
            AccountEnvironments::MAIN => $this->main_account_token,
            AccountEnvironments::SECONDARY => $this->account_user_admin_token_device_1,
        };

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }
}
