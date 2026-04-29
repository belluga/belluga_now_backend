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
     *     receiver_account_profile_id?: ?string,
     *     display_name: ?string,
     *     avatar_url: ?string
     * }|null
     */
    public function resolveUserRecipient(string $userId): ?array;

    /**
     * @return array{
     *     user_id: string,
     *     receiver_account_profile_id?: ?string,
     *     display_name: ?string,
     *     avatar_url: ?string
     * }|null
     */
    public function resolveUserRecipientOwnership(string $userId): ?array;

    /**
     * @return array{
     *     user_id: string,
     *     receiver_account_profile_id: string,
     *     display_name: ?string,
     *     avatar_url: ?string
     * }|null
     */
    public function resolveAccountProfileRecipient(string $accountProfileId): ?array;

    /**
     * @param  array<int, array{type:string,hash:string}>  $contacts
     * @return array<string, array{
     *     contact_hash: string,
     *     type: string,
     *     user_id: string,
     *     receiver_account_profile_id?: string,
     *     display_name: ?string,
     *     avatar_url: ?string,
     *     profile_exposure_level?: string,
     *     inviteable_reasons?: array<int, string>,
     *     source_tags?: array<int, string>,
     *     is_inviteable?: bool
     * }>
     */
    public function matchImportedContacts(array $contacts, mixed $ownerUser, ?string $saltVersion): array;
}
