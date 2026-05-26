<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Application\AccountProfiles\AccountProfileHeroImageResolver;
use Belluga\Events\Application\Events\EventHeroImageResolver;
use Tests\TestCase;

class EventHeroImageResolverTest extends TestCase
{
    public function test_resolves_all_documented_fallback_scenarios_with_distinct_urls(): void
    {
        $resolver = new EventHeroImageResolver(new AccountProfileHeroImageResolver);

        $scenarios = [
            'IMG-01 event cover wins over profile and venue media' => [
                'payload' => $this->eventPayload(
                    thumbUrl: 'https://example.org/img-01-event-cover.jpg',
                    profileCoverUrl: 'https://example.org/img-01-profile-cover.jpg',
                    profileAvatarUrl: 'https://example.org/img-01-profile-avatar.jpg',
                    venueCoverUrl: 'https://example.org/img-01-venue-cover.jpg',
                    venueHeroUrl: 'https://example.org/img-01-venue-hero.jpg',
                    venueAvatarUrl: 'https://example.org/img-01-venue-avatar.jpg',
                    venueLogoUrl: 'https://example.org/img-01-venue-logo.jpg',
                ),
                'expected' => 'https://example.org/img-01-event-cover.jpg',
            ],
            'IMG-03 linked account profile cover wins when event cover is absent' => [
                'payload' => $this->eventPayload(
                    profileCoverUrl: 'https://example.org/img-03-profile-cover.jpg',
                    profileAvatarUrl: 'https://example.org/img-03-profile-avatar.jpg',
                    venueCoverUrl: 'https://example.org/img-03-venue-cover.jpg',
                ),
                'expected' => 'https://example.org/img-03-profile-cover.jpg',
            ],
            'IMG-04 linked account profile avatar wins when profile cover is absent' => [
                'payload' => $this->eventPayload(
                    profileAvatarUrl: 'https://example.org/img-04-profile-avatar.jpg',
                    venueCoverUrl: 'https://example.org/img-04-venue-cover.jpg',
                ),
                'expected' => 'https://example.org/img-04-profile-avatar.jpg',
            ],
            'IMG-05 venue cover wins when event and linked profile media are absent' => [
                'payload' => $this->eventPayload(
                    venueCoverUrl: 'https://example.org/img-05-venue-cover.jpg',
                    venueHeroUrl: 'https://example.org/img-05-venue-hero.jpg',
                    venueAvatarUrl: 'https://example.org/img-05-venue-avatar.jpg',
                    venueLogoUrl: 'https://example.org/img-05-venue-logo.jpg',
                ),
                'expected' => 'https://example.org/img-05-venue-cover.jpg',
            ],
            'IMG-06 venue hero wins when venue cover is absent' => [
                'payload' => $this->eventPayload(
                    venueHeroUrl: 'https://example.org/img-06-venue-hero.jpg',
                    venueAvatarUrl: 'https://example.org/img-06-venue-avatar.jpg',
                    venueLogoUrl: 'https://example.org/img-06-venue-logo.jpg',
                ),
                'expected' => 'https://example.org/img-06-venue-hero.jpg',
            ],
            'IMG-07 venue avatar wins when venue cover and hero are absent' => [
                'payload' => $this->eventPayload(
                    venueAvatarUrl: 'https://example.org/img-07-venue-avatar.jpg',
                    venueLogoUrl: 'https://example.org/img-07-venue-logo.jpg',
                ),
                'expected' => 'https://example.org/img-07-venue-avatar.jpg',
            ],
            'IMG-08 venue logo wins when every higher priority candidate is absent' => [
                'payload' => $this->eventPayload(
                    venueLogoUrl: 'https://example.org/img-08-venue-logo.jpg',
                ),
                'expected' => 'https://example.org/img-08-venue-logo.jpg',
            ],
            'IMG-09 returns null when no valid image candidate exists' => [
                'payload' => $this->eventPayload(),
                'expected' => null,
            ],
            'IMG-10 event party metadata excludes venue party and uses non-venue party cover' => [
                'payload' => [
                    'event_parties' => [
                        [
                            'party_type' => 'venue',
                            'metadata' => [
                                'cover_url' => 'https://example.org/img-10-party-venue-cover.jpg',
                                'avatar_url' => 'https://example.org/img-10-party-venue-avatar.jpg',
                            ],
                        ],
                        [
                            'party_type' => 'artist',
                            'metadata' => [
                                'cover_url' => 'https://example.org/img-10-party-profile-cover.jpg',
                                'avatar_url' => 'https://example.org/img-10-party-profile-avatar.jpg',
                            ],
                        ],
                    ],
                    'venue' => [
                        'cover_url' => 'https://example.org/img-10-venue-cover.jpg',
                    ],
                ],
                'expected' => 'https://example.org/img-10-party-profile-cover.jpg',
            ],
        ];

        foreach ($scenarios as $label => $scenario) {
            $this->assertSame(
                $scenario['expected'],
                $resolver->resolveFromPayload($scenario['payload']),
                $label
            );
        }
    }

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

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(
        ?string $thumbUrl = null,
        ?string $profileCoverUrl = null,
        ?string $profileAvatarUrl = null,
        ?string $venueCoverUrl = null,
        ?string $venueHeroUrl = null,
        ?string $venueAvatarUrl = null,
        ?string $venueLogoUrl = null,
    ): array {
        $payload = [];

        if ($thumbUrl !== null) {
            $payload['thumb'] = [
                'type' => 'image',
                'data' => [
                    'url' => $thumbUrl,
                ],
            ];
        }

        if ($profileCoverUrl !== null || $profileAvatarUrl !== null) {
            $payload['linked_account_profiles'] = [[
                'cover_url' => $profileCoverUrl,
                'avatar_url' => $profileAvatarUrl,
            ]];
        }

        if (
            $venueCoverUrl !== null
            || $venueHeroUrl !== null
            || $venueAvatarUrl !== null
            || $venueLogoUrl !== null
        ) {
            $payload['venue'] = [
                'cover_url' => $venueCoverUrl,
                'hero_image_url' => $venueHeroUrl,
                'avatar_url' => $venueAvatarUrl,
                'logo_url' => $venueLogoUrl,
            ];
        }

        return $payload;
    }
}
