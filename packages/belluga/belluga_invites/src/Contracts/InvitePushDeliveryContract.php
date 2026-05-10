<?php

declare(strict_types=1);

namespace Belluga\Invites\Contracts;

use Belluga\Invites\Models\Tenants\InviteEdge;

interface InvitePushDeliveryContract
{
    public function sendDirectInvite(InviteEdge $edge): void;

    public function sendAcceptedInvite(InviteEdge $edge): void;
}
