<?php

declare(strict_types=1);

return [
    'default_registry_key' => 'account_profile',
    'registries' => [
        [
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'snapshot_collection' => 'favoritable_account_profile_snapshots',
            'snapshot_builder' => App\Integration\Favorites\AccountProfileFavoriteSnapshotBuilder::class,
            'requires_specific_indexes' => true,
            'shared_envelope_fields' => ['registry_key', 'target_type', 'target_id', 'updated_at'],
        ],
    ],
];
