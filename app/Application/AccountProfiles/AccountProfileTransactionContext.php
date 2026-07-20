<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Session;

final readonly class AccountProfileTransactionContext
{
    public function __construct(
        private Database $database,
        private Session $session,
    ) {}

    public function collection(string $name): Collection
    {
        return $this->database->selectCollection($name);
    }

    /** @return array{session: Session} */
    public function rawOptions(): array
    {
        return ['session' => $this->session];
    }
}
