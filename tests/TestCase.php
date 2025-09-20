<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Api\Traits\ClearConfigCacheOnce;
use Tests\Api\Traits\MigrateFreshSeedOnce;
use Tests\Helpers\Landlord;

abstract class TestCase extends BaseTestCase {

    use MigrateFreshSeedOnce, ClearConfigCacheOnce;

    protected string $prefix = "default";

    protected string $host = "nginx";

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearConfigCacheOnce();
        $this->migrateOnce();
    }

    protected string $api_url_admin {
        get {
            return "admin/api";
        }
    }

    protected Landlord $landlord {
        get {
            return new Landlord("landlord");
        }
    }

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
