<?php

declare(strict_types=1);

namespace App\Domain\FoundationControlPlane\Identity;

use App\Domain\FoundationControlPlane\Identity\Exceptions\IdentityAlreadyExistsException;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordIdentityRegistrar
{
    /**
     * @param array<string, mixed> $attributes
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

        $payload = array_merge([
            'identity_state' => 'registered',
            'emails' => $emails->all(),
            'password' => $passwordHash,
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
        ], Arr::except($attributes, ['password', 'emails']));

        $user = AccountUser::create($payload);

        $emails->each(function (string $email) use ($user, $passwordHash): void {
            $user->ensureEmail($email);
            $user->syncCredential('password', $email, $passwordHash);
        });

        return $user;
    }

    /**
     * @param mixed $emails
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
