<?php

namespace Tests;

use Tests\Helpers\AccountEnvironments;

abstract class TestCaseAuthenticated extends TestCase
{
    protected function getHeaders(): array {

        $token = $this->landlord->user_superadmin->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }
}
