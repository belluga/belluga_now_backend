<?php

return [
    App\Providers\AppServiceProvider::class,
    MongoDB\Laravel\MongoDBServiceProvider::class,
    Belluga\Events\EventsServiceProvider::class,
    Belluga\PushHandler\PushHandlerServiceProvider::class,
];
