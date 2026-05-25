<?php

declare(strict_types=1);

namespace Belluga\Invites\Contracts;

interface InviteRecipientProfileProjectionContract
{
    /**
     * @param  array<int, string>  $accountProfileIds
     * @return array<string, array{
     *     receiver_account_profile_id:string,
     *     receiver_user_id:?string,
     *     display_name:?string,
     *     avatar_url:?string
     * }>
     */
    public function profilesByIds(array $accountProfileIds): array;
}
