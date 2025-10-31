<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Tenants\AccountUser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AccountUserCredentialService
{
    public function __construct(
        private readonly AccountUserAccessService $accessService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function link(AccountUser $user, array $payload): array
    {
        $provider = (string) ($payload['provider'] ?? '');
        $subject = (string) ($payload['subject'] ?? '');
        $metadata = Arr::get($payload, 'metadata', []);

        $duplicateExists = AccountUser::query()
            ->where('_id', '!=', new ObjectId((string) $user->_id))
            ->where('credentials', 'elemMatch', [
                'provider' => $provider,
                'subject' => $subject,
            ])->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'subject' => ['This credential is already linked to another identity.'],
            ]);
        }

        $secretHash = null;

        if ($provider === 'password') {
            $secret = $payload['secret'] ?? null;

            if (is_string($secret) && $secret !== '') {
                $secretHash = Hash::make($secret);
                $user->password = $secretHash;
            } elseif ($user->password) {
                $secretHash = $user->password;
            } else {
                throw ValidationException::withMessages([
                    'secret' => ['A password must be provided when linking the first password credential.'],
                ]);
            }

            $this->accessService->ensureEmail($user, $subject);
        }

        $credential = $this->accessService->syncCredential(
            $user,
            $provider,
            $subject,
            $secretHash,
            is_array($metadata) ? $metadata : []
        );

        return [
            'user' => $user->fresh(),
            'credential' => $credential,
        ];
    }

    public function unlink(AccountUser $user, string $credentialId): AccountUser
    {
        $currentCredential = collect($user->credentials ?? [])
            ->first(static function (array $credential) use ($credentialId): bool {
                $currentId = $credential['_id'] ?? $credential['id'] ?? null;
                return $currentId === $credentialId;
            });

        if (! $currentCredential) {
            throw new NotFoundHttpException('Credential not found.');
        }

        if (($currentCredential['provider'] ?? null) === 'password' && $user->identity_state === 'verified') {
            $remainingPasswords = collect($user->credentials ?? [])
                ->reject(static function (array $credential) use ($credentialId): bool {
                    $currentId = $credential['_id'] ?? $credential['id'] ?? null;
                    return ($credential['provider'] ?? null) === 'password' && $currentId === $credentialId;
                })
                ->filter(static fn (array $credential): bool => ($credential['provider'] ?? null) === 'password');

            if ($remainingPasswords->isEmpty()) {
                throw ValidationException::withMessages([
                    'credential_id' => ['Verified identities must keep at least one password credential linked.'],
                ]);
            }
        }

        $removed = $this->accessService->removeCredential($user, $credentialId);

        if (! $removed) {
            throw new NotFoundHttpException('Credential not found.');
        }

        return $user->fresh();
    }
}
