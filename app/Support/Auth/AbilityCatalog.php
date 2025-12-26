<?php

declare(strict_types=1);

namespace App\Support\Auth;

final class AbilityCatalog
{
    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        $abilities = config('abilities.all', []);

        return array_values(array_unique($abilities));
    }
}
