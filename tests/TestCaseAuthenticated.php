<?php

namespace Tests;

abstract class TestCaseAuthenticated extends TestCase
{

    protected function getHeaders(): array {
        return [
            'Authorization' => "Bearer {$this->getGlobal(TestVariableLabels::MAIN_ACCOUNT_TOKEN->value)}",
            'Content-Type' => 'application/json'
        ];
    }

    abstract protected function getGlobalData(): void;
}
