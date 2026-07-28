<?php

declare(strict_types=1);

namespace Tests\Unit\Invites;

use Belluga\Invites\Application\Settings\InviteRuntimeSettingsService;
use Belluga\Invites\Application\Targets\InviteTargetResolverService;
use Belluga\Invites\Contracts\InviteTargetReadContract;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class InviteTargetResolverServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_resolve_falls_back_to_occurrence_linked_account_profile_summary_when_detail_projection_is_lazy(): void
    {
        $targetRead = Mockery::mock(InviteTargetReadContract::class);
        $targetRead->shouldReceive('findEventByIdOrSlug')
            ->once()
            ->with('event-1')
            ->andReturn([
                'id' => 'event-1',
                'slug' => 'evento-teste',
                'title' => 'Evento Teste',
                'date_time_start' => Carbon::parse('2026-03-13 20:00:00'),
                'date_time_end' => Carbon::parse('2026-03-13 23:00:00'),
                'publication' => [
                    'status' => 'published',
                    'publish_at' => Carbon::parse('2026-03-10 12:00:00'),
                ],
                'event_image_url' => 'https://example.org/event.jpg',
                'attributes' => [
                    'attendance_policy' => 'free_confirmation_only',
                    'place_ref' => [
                        'type' => null,
                    ],
                ],
            ]);
        $targetRead->shouldReceive('findOccurrenceForEvent')
            ->once()
            ->with('event-1', 'occ-1')
            ->andReturn([
                'id' => 'occ-1',
                'event_id' => 'event-1',
                'starts_at' => Carbon::parse('2026-03-13 20:00:00'),
                'ends_at' => Carbon::parse('2026-03-13 23:00:00'),
                'effective_ends_at' => Carbon::parse('2026-03-13 23:00:00'),
                'is_event_published' => true,
                'attributes' => [
                    'attendance_policy' => 'either',
                    'linked_account_profiles' => [[
                        'id' => 'band-1',
                        'display_name' => 'Du Jorge',
                        'profile_type' => 'band',
                    ], [
                        'id' => 'expo-1',
                        'display_name' => 'Agro Sul',
                        'profile_type' => 'producer',
                    ]],
                ],
            ]);
        $targetRead->shouldReceive('findEventDetailProjection')
            ->once()
            ->with('event-1', 'occ-1')
            ->andReturn([
                'title' => 'Evento Teste',
                'slug' => 'evento-teste',
                'hero_image_url' => 'https://example.org/event.jpg',
                'location' => 'Guarapari',
                'taxonomy_terms' => [],
                'linked_account_profiles' => [],
                'profile_groups' => [[
                    'id' => 'bandas',
                    'label' => 'Bandas',
                    'member_count' => 2,
                    'members_path' => '/api/v1/events/evento-teste/related_profile_tabs/bandas/members',
                ]],
            ]);

        $service = new InviteTargetResolverService(
            new InviteRuntimeSettingsService,
            $targetRead,
        );

        $resolved = $service->resolve([
            'event_id' => 'event-1',
            'occurrence_id' => 'occ-1',
        ]);

        $this->assertSame(
            'Du Jorge',
            data_get($resolved, 'event_snapshot.linked_account_profiles.0.display_name'),
        );
        $this->assertSame(
            'Agro Sul',
            data_get($resolved, 'event_snapshot.linked_account_profiles.1.display_name'),
        );
        $this->assertSame(
            'Bandas',
            data_get($resolved, 'event_snapshot.profile_groups.0.label'),
        );
        $this->assertSame(
            'either',
            data_get($resolved, 'event_snapshot.attendance_policy'),
        );
    }
}
