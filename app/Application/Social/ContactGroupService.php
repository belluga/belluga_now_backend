<?php

declare(strict_types=1);

namespace App\Application\Social;

use App\Models\Tenants\AccountUser;
use App\Models\Tenants\ContactGroup;
use Belluga\Invites\Support\InviteDomainException;

class ContactGroupService
{
    private const int MAX_GROUPS_PER_USER = 100;

    public function __construct(
        private readonly InviteablePeopleService $inviteablePeople,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(AccountUser $owner): array
    {
        $inviteable = $this->inviteablePeople->inviteableProfileIdSetFor($owner);

        return ContactGroup::query()
            ->where('owner_user_id', $this->ownerId($owner))
            ->orderBy('updated_at', 'desc')
            ->orderBy('_id')
            ->limit(self::MAX_GROUPS_PER_USER)
            ->get()
            ->map(fn (ContactGroup $group): array => $this->normalizeAndPersist($group, $inviteable))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(AccountUser $owner, array $payload): array
    {
        $group = ContactGroup::query()->create([
            'owner_user_id' => $this->ownerId($owner),
            'name' => $this->normalizeName($payload['name'] ?? ''),
            'recipient_account_profile_ids' => $this->filterInviteableIds(
                ids: $payload['recipient_account_profile_ids'] ?? [],
                inviteable: $this->inviteablePeople->inviteableProfileIdSetFor($owner),
            ),
        ]);

        return $this->toPayload($group);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(AccountUser $owner, string $groupId, array $payload): array
    {
        $group = $this->findOwned($owner, $groupId);
        if (array_key_exists('name', $payload)) {
            $group->name = $this->normalizeName($payload['name']);
        }
        if (array_key_exists('recipient_account_profile_ids', $payload)) {
            $group->recipient_account_profile_ids = $this->filterInviteableIds(
                ids: $payload['recipient_account_profile_ids'],
                inviteable: $this->inviteablePeople->inviteableProfileIdSetFor($owner),
            );
        }
        $group->save();

        return $this->toPayload($group->fresh());
    }

    public function delete(AccountUser $owner, string $groupId): void
    {
        $this->findOwned($owner, $groupId)->delete();
    }

    /**
     * @param  array<string, true>  $inviteable
     */
    private function normalizeAndPersist(ContactGroup $group, array $inviteable): array
    {
        $filtered = $this->filterInviteableIds(
            ids: $group->recipient_account_profile_ids ?? [],
            inviteable: $inviteable,
        );

        if ($filtered !== array_values((array) ($group->recipient_account_profile_ids ?? []))) {
            $group->recipient_account_profile_ids = $filtered;
            $group->save();
        }

        return $this->toPayload($group->fresh());
    }

    /**
     * @param  array<string, true>  $inviteable
     * @return array<int, string>
     */
    private function filterInviteableIds(mixed $ids, array $inviteable): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $deduped = [];
        foreach ($ids as $id) {
            $profileId = trim((string) $id);
            if ($profileId === '' || ! isset($inviteable[$profileId])) {
                continue;
            }
            $deduped[$profileId] = true;
        }

        return array_keys($deduped);
    }

    private function findOwned(AccountUser $owner, string $groupId): ContactGroup
    {
        /** @var ContactGroup|null $group */
        $group = ContactGroup::query()
            ->where('owner_user_id', $this->ownerId($owner))
            ->whereKey($groupId)
            ->first();

        if (! $group instanceof ContactGroup) {
            throw new InviteDomainException('contact_group_not_found', 404);
        }

        return $group;
    }

    private function normalizeName(mixed $name): string
    {
        $normalized = trim((string) $name);
        if ($normalized === '') {
            throw new InviteDomainException('contact_group_name_required', 422);
        }

        return mb_substr($normalized, 0, 80);
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(?ContactGroup $group): array
    {
        if (! $group instanceof ContactGroup) {
            return [];
        }

        return [
            'id' => (string) $group->getKey(),
            'name' => (string) $group->name,
            'recipient_account_profile_ids' => array_values((array) ($group->recipient_account_profile_ids ?? [])),
            'created_at' => $group->created_at?->toISOString(),
            'updated_at' => $group->updated_at?->toISOString(),
        ];
    }

    private function ownerId(AccountUser $owner): string
    {
        return (string) ($owner->getKey() ?? $owner->_id ?? $owner->getAuthIdentifier() ?? '');
    }
}
