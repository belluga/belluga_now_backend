<?php

namespace App\Models\Landlord;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use MongoDB\Laravel\Eloquent\DocumentModel;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class PersonalAccessToken extends SanctumToken
{
    use DocumentModel, UsesLandlordConnection;

    protected $table = 'personal_access_tokens';
    protected $keyType = 'string';
}
