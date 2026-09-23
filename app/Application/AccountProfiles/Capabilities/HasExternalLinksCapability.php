<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

final class HasExternalLinksCapability implements AccountProfileCapabilityContract
{
    public function definition(): array
    {
        return [
            'key' => 'has_external_links',
            'domain' => AccountProfileCapabilityDomain::ProfileContent->value,
            'value_type' => 'boolean',
            'default_value' => false,
            'fail_closed_value' => false,
            'parameters' => [
                ['key' => 'max_links', 'value_type' => 'integer', 'default_value' => 3, 'fail_closed_value' => 0, 'validations' => [['rule' => 'min', 'value' => 0]]],
            ],
            'resources' => [
                'external_links' => ['operations' => array_map(
                    static fn (string $key): array => ['key' => $key, 'ability' => 'account-users:update'],
                    ['create', 'update', 'delete'],
                )],
            ],
        ];
    }
}
