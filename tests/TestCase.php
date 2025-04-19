<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

    use MigrateFreshSeedOnce;

    protected string $prefix = "default";

    protected function getGlobal($key): mixed{
        global $params;
        return $params["{$this->prefix}.$key"];
    }

    protected function setGlobal($key, $value): void{
        global $params;
        $params["{$this->prefix}.$key"] = $value;
    }
}
