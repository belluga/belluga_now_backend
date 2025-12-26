<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use App\Application\LandlordUsers\LandlordUserAccessService;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\EmbedsMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class LandlordUser extends Authenticatable {

    use HasApiTokens, Notifiable, SoftDeletes, DocumentModel, UsesLandlordConnection;

    protected $table = 'landlord_users';

    protected $fillable = [
        'name',
        'emails',
        'phones',
        'password',
        'identity_state',
        'credentials',
        'promotion_audit',
        'verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (LandlordUser $user): void {
            $user->identity_state ??= 'registered';
            $user->credentials ??= [];
            $user->promotion_audit ??= [];
            $user->emails ??= [];
            $user->phones ??= [];
        });
    }

    public function landlordRole(): BelongsTo {
        return $this->belongsTo(LandlordRole::class);
    }

    public function tenantRoles(): EmbedsMany {
        return $this->embedsMany(TenantRole::class, 'tenant_roles');
    }

    public function getAccessToIds(): array
    {
        return $this->accessService()->tenantAccessIds($this);
    }

    public function getPermissions(?Tenant $tenant = null): array
    {
        return $this->accessService()->permissions($this, $tenant);
    }

    public function tokenCan(string $ability): bool
    {
        $token = $this->currentAccessToken();

        if ($token) {
            return $token->can($ability);
        }

        return $this->accessService()->tokenAllows($this, $ability);
    }

    public function syncCredential(string $provider, string $subject, ?string $secretHash = null, array $metadata = []): array
    {
        return $this->accessService()->syncCredential($this, $provider, $subject, $secretHash, $metadata);
    }

    public function ensureEmail(string $email): void
    {
        $this->accessService()->ensureEmail($this, $email);
    }

    private function accessService(): LandlordUserAccessService
    {
        return app(LandlordUserAccessService::class);
    }

}
