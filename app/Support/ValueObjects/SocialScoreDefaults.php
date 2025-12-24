<?php

declare(strict_types=1);

namespace App\Support\ValueObjects;

final class SocialScoreDefaults
{
    /**
     * @return array{invites_accepted:int, presences_confirmed:int, rank_label:?string}
     */
    public static function payload(): array
    {
        return [
            'invites_accepted' => 0,
            'presences_confirmed' => 0,
            'rank_label' => null,
        ];
    }
}
