<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

enum AccountProfileRegistryDefaultUpsertOutcome
{
    case Inserted;
    case Existing;
    case ConvergedAfterDuplicate;

    public function requiresExistingDefaultRepair(): bool
    {
        return $this !== self::Inserted;
    }

    public function requiresTypeSetInvalidation(): bool
    {
        return $this !== self::Existing;
    }
}
