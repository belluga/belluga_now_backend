<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class InArrayItemRule implements ValidationRule
{
    protected string $key;
    protected string $table;
    protected string $connection;
    protected bool $shouldExist;

    public function __construct(string $connection,string $table ,string $key, $shouldExist = true)
    {
        $this->key = $key;
        $this->table = $table;
        $this->connection = $connection;
        $this->shouldExist = $shouldExist;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = DB::connection($this->connection)
            ->table($this->table)
            ->where($this->key, $value);

        $exists = $query->exists();

        if($this->shouldExist){
            if (!$exists) {
                $fail("The selected :attribute is not a valid option.");
            }
        }
    }
}
