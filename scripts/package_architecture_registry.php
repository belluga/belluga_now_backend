<?php

declare(strict_types=1);

return [
    'packages/belluga/belluga_events' => [
        'integration_mode' => 'host-integrated',
        'route_ownership' => 'host-owned-routes',
    ],
    'packages/belluga/belluga_invites' => [
        'integration_mode' => 'host-integrated',
        'route_ownership' => 'host-owned-routes',
    ],
    'packages/belluga/belluga_map_pois' => [
        'integration_mode' => 'host-integrated',
        'route_ownership' => 'host-owned-routes',
    ],
    'packages/belluga/belluga_push_handler' => [
        'integration_mode' => 'host-integrated',
        'route_ownership' => 'host-owned-routes',
    ],
    'packages/belluga/belluga_settings' => [
        'integration_mode' => 'shared-kernel',
        'route_ownership' => 'host-owned-routes',
    ],
    'packages/belluga/belluga_favorites' => [
        'integration_mode' => 'host-integrated',
        'route_ownership' => 'host-owned-routes',
    ],
    'packages/belluga/belluga_media' => [
        'integration_mode' => 'host-integrated',
        'route_ownership' => 'host-owned-routes',
    ],
    'packages/belluga/belluga_deep_links' => [
        'integration_mode' => 'host-integrated',
        'route_ownership' => 'host-owned-routes',
    ],
];
