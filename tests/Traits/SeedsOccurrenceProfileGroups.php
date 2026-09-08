<?php

declare(strict_types=1);

namespace Tests\Traits;

use Belluga\Events\Application\Events\EventAggregateWriteService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;

trait SeedsOccurrenceProfileGroups
{
    /** @param array<int, array<string, mixed>> $groups */
    protected function seedOccurrenceProfileGroups(
        Event $event,
        EventOccurrence $occurrence,
        array $groups,
    ): void {
        $occurrence->forceFill([
            'own_profile_groups' => [],
            'profile_groups' => [],
        ])->save();

        $writer = app(EventAggregateWriteService::class);
        foreach ($groups as $group) {
            $result = $writer->createOccurrenceGroup(
                $event,
                $occurrence,
                (string) ($group['label'] ?? ''),
            );
            $createdGroups = array_values((array) ($result['profile_groups'] ?? []));
            $createdGroup = $createdGroups[array_key_last($createdGroups)] ?? [];
            $groupId = (string) ($createdGroup['id'] ?? '');
            if ($groupId === '') {
                throw new \RuntimeException('Canonical Event group fixture creation returned no group id.');
            }
            $memberIds = array_values((array) ($group['account_profile_ids'] ?? []));
            if ($memberIds === []) {
                continue;
            }

            $writer->patchOccurrenceGroupMembers(
                $event,
                $occurrence,
                $groupId,
                $memberIds,
                [],
            );
        }
    }
}
