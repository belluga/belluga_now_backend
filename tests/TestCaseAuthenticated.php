<?php

namespace Tests;

use Tests\Traits\EnsuresSystemInitialization;

abstract class TestCaseAuthenticated extends TestCase
{
    use EnsuresSystemInitialization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSystemInitialized();
    }

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
