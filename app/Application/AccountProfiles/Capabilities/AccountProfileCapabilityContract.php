<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

interface AccountProfileCapabilityContract
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array;
}
