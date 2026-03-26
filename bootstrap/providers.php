<?php

return [
    App\Providers\AppServiceProvider::class,
    MongoDB\Laravel\MongoDBServiceProvider::class,
    Belluga\Events\EventsServiceProvider::class,
    Belluga\Settings\SettingsServiceProvider::class,
    Belluga\PushHandler\PushHandlerServiceProvider::class,
    Belluga\Ticketing\TicketingServiceProvider::class,
    Belluga\MapPois\MapPoisServiceProvider::class,
    Belluga\Invites\InvitesServiceProvider::class,
    Belluga\Favorites\FavoritesServiceProvider::class,
    Belluga\Media\MediaServiceProvider::class,
    App\Providers\PackageIntegration\MediaIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\SettingsIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\EventsIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\MapPoisIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\PushIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\TicketingIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\InvitesIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\FavoritesIntegrationServiceProvider::class,
];
