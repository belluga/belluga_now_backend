<?php

namespace App\Models\Landlord;

use App\Casts\BrandingDataCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use MongoDB\Laravel\Eloquent\Model;

class Landlord extends Model
{
    use HasFactory, UsesLandlordConnection;

    protected $fillable = [
        'name',
        'branding_data',
    ];

    protected $casts = [
        'branding_data' => BrandingDataCast::class,
    ];

    public function tenants()
    {
        return $this->hasMany(Tenant::class);
    }
}
