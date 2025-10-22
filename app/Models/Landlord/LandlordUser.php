<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\BSON\ObjectId;
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

    public function getAccessToIds(): array{

        $tenant_roles_array = $this->tenant_roles ?? [];

        return collect($tenant_roles_array)
            ->pluck('tenant_id')
            ->toArray();
    }

    public function getPermissions(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? Tenant::current();

        if($tenant){
            return $this->getTenantPermissions();
        }

        return $this->landlordRole->permissions;
    }

    protected function getTenantPermissions(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? Tenant::current();

        return collect($this->tenant_roles)
            ->where('tenant_id', "==", $tenant->id)
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->toArray();

    }


    public function tokenCan(string $ability): bool
    {

        $permissions = $this->getPermissions();

        $parts = explode(':', $ability, 2);

        if (count($parts) !== 2) {
            return false;
        }
        [$resource, $action] = $parts;

        return in_array("*", $permissions) ||
            in_array("$resource:*", $permissions) ||
            in_array("$resource:$action", $permissions);
    }

    public function syncCredential(string $provider, string $subject, ?string $secretHash = null, array $metadata = []): array
    {
        $credentials = collect($this->credentials);

        $index = $credentials->search(static function (array $credential) use ($provider, $subject): bool {
            return ($credential['provider'] ?? null) === $provider
                && ($credential['subject'] ?? null) === $subject;
        });

        if ($index !== false) {
            $credential = $credentials->get($index);
            if ($secretHash !== null) {
                $credential['secret_hash'] = $secretHash;
            }
            if (! empty($metadata)) {
                $credential['metadata'] = $metadata;
            }
            $credentials->put($index, $credential);
            $this->credentials = $credentials->values()->all();
            $this->save();

            return $this->credentials[$index];
        }

        $credential = [
            '_id' => (string) new ObjectId(),
            'provider' => $provider,
            'subject' => $subject,
            'secret_hash' => $secretHash,
            'metadata' => $metadata,
            'linked_at' => Carbon::now(),
            'last_used_at' => null,
        ];

        $credentials->push($credential);
        $this->credentials = $credentials->values()->all();
        $this->save();

        return $credential;
    }

    public function ensureEmail(string $email): void
    {
        $emails = $this->emails ?? [];

        if (! in_array($email, $emails, true)) {
            $emails[] = $email;
            $this->emails = array_values($emails);
            $this->save();
        }
    }

}
