<?php

namespace Tests;

abstract class TestCaseAuthenticated extends TestCase
{

    protected string $base_api_url {
        get {
            return "admin/api/";
        }
    }

    protected function getHeaders(): array {

        $token = $this->landlord->user_superadmin->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }
}
