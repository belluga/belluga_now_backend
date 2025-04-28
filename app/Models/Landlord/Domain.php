<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Domain extends Model
{
    use UsesLandlordConnection;

    protected $fillable = [
        'host',
    ];
}
