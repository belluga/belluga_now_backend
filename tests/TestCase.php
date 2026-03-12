<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Api\Traits\ClearConfigCacheOnce;
use Tests\Api\Traits\MigrateFreshSeedOnce;
use Tests\Helpers\Landlord;

abstract class TestCase extends BaseTestCase
{
    use ClearConfigCacheOnce, MigrateFreshSeedOnce;

    protected string $prefix = 'default';

    protected string $host {
        get {
            $host = parse_url(config('app.url'), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }

            return 'nginx';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearConfigCacheOnce();
        $this->migrateOnce();
        $_SERVER['HTTP_HOST'] = $this->host;
        $_SERVER['SERVER_NAME'] = $this->host;
        $this->withServerVariables(['HTTP_HOST' => $this->host]);
    }

    protected function normalizeTestUri(string $uri): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        if ($uri === '') {
            return "http://{$this->host}/";
        }

        if ($uri[0] !== '/') {
            $uri = "/{$uri}";
        }

        return "http://{$this->host}{$uri}";
    }

    public function call(
        $method,
        $uri,
        $parameters = [],
        $cookies = [],
        $files = [],
        $server = [],
        $content = null
    ) {
        $uri = $this->normalizeTestUri($uri);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected string $api_url_admin {
        get {
            return 'admin/api/v1';
        }
    }

    protected Landlord $landlord {
        get {
            return new Landlord('landlord');
        }
    }

    protected function getGlobal($key): mixed
    {
        global $params;

        if (! isset($params)) {
            return null;
        }

        $key_to_retrieve = "{$this->prefix}.$key";

        return array_key_exists($key_to_retrieve, $params) ? $params[$key_to_retrieve] : null;
    }

    protected function setGlobal($key, $value): void
    {
        global $params;
        $params["{$this->prefix}.$key"] = $value;
    }
}
