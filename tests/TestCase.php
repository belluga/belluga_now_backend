<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

    use MigrateFreshSeedOnce;

    protected string $prefix = "default";

    protected function getGlobal($key): mixed{
        global $params;

        if(!isset($params)){
            return null;
        }

        $key_to_retrieve = "{$this->prefix}.$key";
        return array_key_exists($key_to_retrieve, $params) ? $params[$key_to_retrieve] : null;
    }

    protected function setGlobal($key, $value): void{
        global $params;
        $params["{$this->prefix}.$key"] = $value;
    }
}
