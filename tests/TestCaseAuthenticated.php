<?php

namespace Tests;

abstract class TestCaseAuthenticated extends TestCase
{
    protected string $token {
        get {
            return $this->getGlobal(TestVariableLabels::MAIN_ACCOUNT_TOKEN->value);
        }
    }

    protected function getHeaders(): array {

        print("Bearer $this->token");

        return [
            'Authorization' => "Bearer $this->token",
            'Content-Type' => 'application/json'
        ];
    }

    abstract protected function getGlobalData(): void;
}
