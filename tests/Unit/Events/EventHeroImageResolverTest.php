<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Application\AccountProfiles\AccountProfileHeroImageResolver;
use Belluga\Events\Application\Events\EventHeroImageResolver;
use Tests\TestCase;

class EventHeroImageResolverTest extends TestCase
{
    public function test_resolves_event_thumb_data_url_before_venue_media(): void
    {
        $resolver = new EventHeroImageResolver(new AccountProfileHeroImageResolver);

        $this->assertSame('https://example.org/event-cover.jpg', $resolver->resolveFromPayload([
            'thumb' => [
                'type' => 'image',
                'data' => [
                    'url' => 'https://example.org/event-cover.jpg',
                ],
            ],
            'linked_account_profiles' => [[
                'cover_url' => 'https://example.org/profile-cover.jpg',
                'avatar_url' => 'https://example.org/profile-avatar.jpg',
            ]],
            'venue' => [
                'cover_url' => 'https://example.org/venue-cover.jpg',
                'hero_image_url' => 'https://example.org/venue-hero.jpg',
            ],
        ]));
    }

    public function test_resolves_linked_profile_cover_before_venue_media_when_event_thumb_is_absent(): void
    {
        $resolver = new EventHeroImageResolver(new AccountProfileHeroImageResolver);

        $this->assertSame('https://example.org/profile-cover.jpg', $resolver->resolveFromPayload([
            'linked_account_profiles' => [[
                'cover_url' => '',
                'avatar_url' => '',
            ], [
                'cover_url' => 'https://example.org/profile-cover.jpg',
                'avatar_url' => 'https://example.org/second-avatar.jpg',
            ]],
            'venue' => [
                'cover_url' => 'https://example.org/venue-cover.jpg',
                'hero_image_url' => 'https://example.org/venue-hero.jpg',
            ],
        ]));
    }

    public function test_resolves_event_party_metadata_before_venue_media_when_linked_profiles_are_absent(): void
    {
        $resolver = new EventHeroImageResolver(new AccountProfileHeroImageResolver);

        $this->assertSame('https://example.org/party-cover.jpg', $resolver->resolveFromPayload([
            'event_parties' => [[
                'party_type' => 'venue',
                'metadata' => [
                    'cover_url' => 'https://example.org/location-cover.jpg',
                    'avatar_url' => 'https://example.org/location-avatar.jpg',
                ],
            ], [
                'party_type' => 'artist',
                'metadata' => [
                    'cover_url' => 'https://example.org/party-cover.jpg',
                    'avatar_url' => 'https://example.org/party-avatar.jpg',
                ],
            ]],
            'venue' => [
                'cover_url' => 'https://example.org/venue-cover.jpg',
                'hero_image_url' => 'https://example.org/venue-hero.jpg',
            ],
        ]));
    }
}
