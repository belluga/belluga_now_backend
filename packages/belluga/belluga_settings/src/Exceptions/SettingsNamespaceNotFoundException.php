<?php

declare(strict_types=1);

namespace Belluga\Settings\Exceptions;

use RuntimeException;

class SettingsNamespaceNotFoundException extends RuntimeException
{
    public function __construct(string $namespace, string $scope)
    {
        parent::__construct("Settings namespace [{$namespace}] was not found for scope [{$scope}].");
    }
}
