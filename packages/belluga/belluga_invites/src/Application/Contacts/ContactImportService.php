<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Contacts;

use Belluga\Invites\Contracts\InviteIdentityGatewayContract;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

class ContactImportService
{
    public function __construct(
        private readonly InviteIdentityGatewayContract $identityGateway,
    ) {}

    /**
     * @param  array{contacts:array<int, array{type:string,hash:string}>,salt_version?:string|null}  $payload
     * @return array<string, mixed>
     */
    public function import(mixed $user, array $payload): array
    {
        $ownerUserId = $this->userId($user);
        if ($ownerUserId === null) {
            throw new InviteDomainException('auth_required', 401);
        }

        $contacts = array_values(array_filter(
            array_map(static function (array $contact): ?array {
                $type = trim((string) ($contact['type'] ?? ''));
                $hash = trim((string) ($contact['hash'] ?? ''));
                if ($type === '' || $hash === '') {
                    return null;
                }

                return ['type' => $type, 'hash' => $hash];
            }, $payload['contacts'] ?? []),
            static fn (?array $contact): bool => $contact !== null
        ));

        $saltVersion = isset($payload['salt_version']) ? (string) $payload['salt_version'] : null;
        $matches = $this->identityGateway->matchImportedContacts($contacts, $user, $saltVersion);
        $now = Carbon::now();

        foreach ($contacts as $contact) {
            $hash = $contact['hash'];
            /** @var ContactHashDirectory|null $directory */
            $directory = ContactHashDirectory::query()
                ->where('importing_user_id', $ownerUserId)
                ->where('contact_hash', $hash)
                ->first();
            $directory ??= new ContactHashDirectory([
                'importing_user_id' => $ownerUserId,
                'contact_hash' => $hash,
                'imported_at' => $now,
            ]);

            $match = $matches[$hash] ?? null;
            $directory->fill([
                'type' => $contact['type'],
                'salt_version' => $saltVersion,
                'matched_user_id' => $match['user_id'] ?? null,
                'match_snapshot' => $match === null
                    ? null
                    : [
                        'display_name' => $match['display_name'] ?? null,
                        'avatar_url' => $match['avatar_url'] ?? null,
                    ],
                'last_seen_at' => $now,
            ]);
            $directory->save();
        }

        return [
            'tenant_id' => $this->currentTenantId(),
            'matches' => array_values($matches),
            'unmatched_count' => max(0, count($contacts) - count($matches)),
        ];
    }

    private function userId(mixed $user): ?string
    {
        if (! is_object($user)) {
            return null;
        }

        $id = null;
        if (method_exists($user, 'getKey')) {
            $id = $user->getKey();
        }
        if ($id === null && property_exists($user, '_id')) {
            $id = $user->_id;
        }
        if ($id === null && method_exists($user, 'getAttribute')) {
            $id = $user->getAttribute('_id');
        }
        if ($id === null && method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
        }

        return is_scalar($id) ? (string) $id : null;
    }

    private function currentTenantId(): ?string
    {
        $tenantId = Context::get('tenantId');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
