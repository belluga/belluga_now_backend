<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use MongoDB\Laravel\Eloquent\DocumentModel;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class PersonalAccessToken extends SanctumToken
{
    use DocumentModel, UsesTenantConnection;

    protected $table = 'personal_access_tokens';
    protected $keyType = 'string';
}
