<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

/**
 * Ensures a normalized email address does not already exist inside the target collection.
 */
class EmailAvailableRule implements ValidationRule
{
    public function __construct(
        protected string $connection,
        protected string $table,
        protected ?string $column = 'emails',
        protected ?string $ignoreId = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $email = strtolower($value);

        $query = DB::connection($this->connection)
            ->table($this->table)
            ->where($this->column ?? 'emails', 'all', [$email]);

        if ($this->ignoreId !== null) {
            $objectId = $this->asObjectId($this->ignoreId);
            $query->where('_id', '!=', $objectId ?? $this->ignoreId);
        }

        if ($query->exists()) {
            $fail('The provided email is already registered.');
        }
    }

    protected function asObjectId(string $value): ?ObjectId
    {
        try {
            return new ObjectId($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
