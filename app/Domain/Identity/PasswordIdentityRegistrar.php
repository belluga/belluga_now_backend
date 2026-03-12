<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Application\Accounts\AccountUserAccessService;
use App\Exceptions\Identity\IdentityAlreadyExistsException;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordIdentityRegistrar
{
    public function __construct(
        private readonly AccountUserAccessService $accessService
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws IdentityAlreadyExistsException
     */
    public function register(array $attributes): AccountUser
    {
        $emails = $this->normalizeEmails($attributes['emails'] ?? null);

        if ($emails->isEmpty()) {
            throw new \InvalidArgumentException('At least one email is required for password registration.');
        }

        if (! isset($attributes['password']) || ! is_string($attributes['password'])) {
            throw new \InvalidArgumentException('Password is required for password registration.');
        }

        $existing = AccountUser::withTrashed()
            ->whereRaw(['emails' => ['$in' => $emails->all()]])
            ->first();

        if ($existing) {
            throw new IdentityAlreadyExistsException($emails->all());
        }

        $passwordHash = Hash::make($attributes['password']);

        $now = Carbon::now();

        $payload = array_merge([
            'identity_state' => 'registered',
            'emails' => $emails->all(),
            'first_seen_at' => $now,
            'registered_at' => $now,
            'password' => $passwordHash,
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
            'merged_source_ids' => [],
            'promotion_audit' => array_merge([
                [
                    'from_state' => null,
                    'to_state' => 'registered',
                    'promoted_at' => $now,
                    'operator_id' => null,
                    'source_user_id' => null,
                    'reason' => null,
                ],
            ], $attributes['promotion_audit'] ?? []),
        ], Arr::except($attributes, ['password', 'emails']));

        $user = AccountUser::create($payload);

        $service = $this->accessService;

        $emails->each(function (string $email) use ($user, $passwordHash, $service): void {
            $service->ensureEmail($user, $email);
            $service->syncCredential($user, 'password', $email, $passwordHash);
        });

        return $user;
    }

    /**
     * @return Collection<int, string>
     */
    private function normalizeEmails(mixed $emails): Collection
    {
        if ($emails === null) {
            return collect();
        }

        if (is_string($emails)) {
            $emails = [$emails];
        }

        if (! is_array($emails)) {
            return collect();
        }

        return collect($emails)
            ->filter(fn ($email): bool => is_string($email) && $email !== '')
            ->map(fn (string $email): string => Str::lower($email))
            ->unique()
            ->values();
    }
}
