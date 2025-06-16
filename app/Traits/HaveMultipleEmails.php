<?php

namespace App\Traits;

use MongoDB\Laravel\Relations\MorphTo;

trait HaveMultipleEmails
{
    public function addEmail(string $email): void {
        $this->update(
            ['$push' => ['emails' => $email]]
        );
    }

    public function removeEmail(string $email): void {
        $this->update(
            [],
            ['$pull' => ['emails' => $email]]
        );
    }
}
