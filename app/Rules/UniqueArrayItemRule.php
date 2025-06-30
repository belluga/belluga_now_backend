<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueArrayItemRule implements ValidationRule
{
    protected string $key;
    protected string $table;
    protected string $connection;

    public function __construct(string $connection,string $table ,string $key)
    {
        $this->key = $key;
        $this->table = $table;
        $this->connection = $connection;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = DB::connection($this->connection)
            ->table($this->table)
            ->where(
                $this->key, 'all', $value
            );

        $exists = $query->exists();

        if ($exists) {
            $fail("$attribute já utilizado.");
        }
    }
}
