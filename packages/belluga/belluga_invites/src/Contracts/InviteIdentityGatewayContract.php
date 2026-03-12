<?php

declare(strict_types=1);

namespace Belluga\Invites\Contracts;

interface InviteIdentityGatewayContract
{
    /**
     * @return array{
     *     principal: array{kind:string,id:string},
     *     issued_by_user_id: string,
     *     account_profile_id: ?string,
     *     display_name: ?string,
     *     avatar_url: ?string
     * }
     */
    public function resolveInviterPrincipal(mixed $user, ?string $accountProfileId): array;

    /**
     * @return array{
     *     user_id: string,
     *     display_name: ?string,
     *     avatar_url: ?string
     * }|null
     */
    public function resolveUserRecipient(string $userId): ?array;

    /**
     * @param  array<int, array{type:string,hash:string}>  $contacts
     * @return array<string, array{
     *     contact_hash: string,
     *     type: string,
     *     user_id: string,
     *     display_name: ?string,
     *     avatar_url: ?string
     * }>
     */
    public function matchImportedContacts(array $contacts, mixed $ownerUser, ?string $saltVersion): array;
}
