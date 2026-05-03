<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use Illuminate\Database\Eloquent\Model;
use MongoDB\Laravel\Eloquent\DocumentModel;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class PhoneOtpChallenge extends Model
{
    use DocumentModel;
    use UsesTenantConnection;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_VERIFIED = 'verified';

    public const string STATUS_SUPERSEDED = 'superseded';

    public const string STATUS_EXPIRED = 'expired';

    public const string STATUS_LOCKED = 'locked';

    protected $table = 'phone_otp_challenges';

    protected $fillable = [
        'phone',
        'phone_hash',
        'code_hash',
        'status',
        'delivery_channel',
        'delivery_webhook_url',
        'expires_at',
        'resend_available_at',
        'verified_at',
        'attempts',
        'max_attempts',
        'anonymous_user_ids',
        'device_name',
        'requested_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'resend_available_at' => 'datetime',
        'verified_at' => 'datetime',
        'requested_at' => 'datetime',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
    ];
}
