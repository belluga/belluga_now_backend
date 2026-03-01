<?php

return [
    App\Providers\AppServiceProvider::class,
    MongoDB\Laravel\MongoDBServiceProvider::class,
    Belluga\Events\EventsServiceProvider::class,
    Belluga\Settings\SettingsServiceProvider::class,
    Belluga\PushHandler\PushHandlerServiceProvider::class,
    Belluga\Ticketing\TicketingServiceProvider::class,
];
