<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

final class HasGalleryCapability implements AccountProfileCapabilityContract
{
    public function definition(): array
    {
        $operations = array_map(static fn (string $key): array => ['key' => $key, 'ability' => 'account-users:update'], ['create', 'update', 'delete', 'reorder']);

        return [
            'key' => 'has_gallery',
            'domain' => AccountProfileCapabilityDomain::ProfileContent->value,
            'value_type' => 'boolean',
            'default_value' => false,
            'fail_closed_value' => false,
            'parameters' => [
                ['key' => 'max_groups', 'value_type' => 'integer', 'default_value' => 6, 'fail_closed_value' => 0, 'validations' => [['rule' => 'min', 'value' => 0]]],
                ['key' => 'max_items_per_group', 'value_type' => 'integer', 'default_value' => 12, 'fail_closed_value' => 0, 'validations' => [['rule' => 'min', 'value' => 0]]],
            ],
            'resources' => [
                'gallery_groups' => ['operations' => $operations],
                'gallery_items' => ['operations' => $operations],
            ],
        ];
    }
}
