<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Transactions;

use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Session;

final readonly class EventTransactionContext
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

    public function database(): Database
    {
        return $this->database;
    }

    public function session(): Session
    {
        return $this->session;
    }
}
