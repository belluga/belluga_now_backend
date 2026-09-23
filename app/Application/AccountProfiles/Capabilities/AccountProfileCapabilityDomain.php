<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

enum AccountProfileCapabilityDomain: string
{
    case Visibility = 'visibility';
    case Relationships = 'relationships';
    case ProfileContent = 'profile_content';
    case Events = 'events';
    case Location = 'location';
}
