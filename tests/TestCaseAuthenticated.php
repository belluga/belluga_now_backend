<?php

namespace Tests;

use Tests\Helpers\AccountEnvironments;

abstract class TestCaseAuthenticated extends TestCase
{
    protected function getHeaders(AccountEnvironments $accountEnv = AccountEnvironments::MAIN): array {

        $token = match ($accountEnv) {
            AccountEnvironments::MAIN => $this->landlord->user_superadmin->token,
            AccountEnvironments::SECONDARY => $this->account_user_admin_token_device_1,
        };

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }
}
