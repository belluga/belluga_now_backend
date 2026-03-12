<?php

namespace Tests\Helpers;

abstract class Labels
{
    protected string $base_label;

    public function __construct(string $base_label)
    {
        $this->base_label = $base_label;
    }

    protected function getGlobal($key): mixed
    {
        global $params;

        if (! isset($params)) {
            return null;
        }

        return array_key_exists($key, $params) ? $params[$key] : null;
    }

    protected function setGlobal($key, $value): void
    {
        global $params;
        $params[$key] = $value;
    }

    abstract public function toArray(): array;
}
