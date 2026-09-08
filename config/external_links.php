<?php

declare(strict_types=1);

return [
    // Temporary source seam. The plan-capacity provider will replace this
    // configuration lookup without changing the Account Profile contract.
    'max_per_profile' => env('ACCOUNT_PROFILE_EXTERNAL_LINKS_LIMIT'),
];
