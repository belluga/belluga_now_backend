<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use Belluga\ContactChannels\ContactChannelCollectionNormalizer;
use Belluga\ContactChannels\Registry\ContactChannelDefinitionRegistry;
use Belluga\ContactChannels\Support\ContactChannelIdentifierGeneratorContract;
use Belluga\ContactChannels\Support\RandomContactChannelIdentifierGenerator;
use Illuminate\Support\ServiceProvider;

final class ContactChannelsIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ContactChannelDefinitionRegistry::class,
            static fn (): ContactChannelDefinitionRegistry => ContactChannelDefinitionRegistry::withFirstDeliveryDefinitions(),
        );
        $this->app->singleton(
            ContactChannelIdentifierGeneratorContract::class,
            RandomContactChannelIdentifierGenerator::class,
        );
        $this->app->bind(ContactChannelCollectionNormalizer::class, function (): ContactChannelCollectionNormalizer {
            return new ContactChannelCollectionNormalizer(
                $this->app->make(ContactChannelDefinitionRegistry::class),
                $this->app->make(ContactChannelIdentifierGeneratorContract::class),
            );
        });
    }
}
