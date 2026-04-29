<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\AccountProfiles\AccountProfileBootstrapService;
use App\Domain\Identity\AnonymousIdentityMerger;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Jobs\Auth\DeliverPhoneOtpWebhookJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\PhoneOtpChallenge;
use App\Support\Auth\AbilityCatalog;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

class TenantPhoneOtpAuthService
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNormalizer,
        private readonly PhoneOtpDeliverySettingsResolver $deliverySettings,
        private readonly TenantPublicAuthMethodResolver $authMethodResolver,
        private readonly AnonymousIdentityMerger $identityMerger,
        private readonly AccountProfileBootstrapService $profileBootstrapper,
        private readonly TenantScopedAccessTokenService $tenantScopedAccessTokenService,
    ) {}

    /**
     * @param  array{phone:string,device_name?:string|null,delivery_channel?:string|null}  $payload
     */
    public function challenge(array $payload): PhoneOtpChallengeResult
    {
        $this->assertPhoneOtpEnabled();

        $settings = $this->deliverySettings->resolve($payload['delivery_channel'] ?? null);
        $phone = $this->phoneNormalizer->normalize($payload['phone']);
        $now = Carbon::now();

        $activeChallenge = $this->activeChallengeForPhone($phone, $now);
        if ($activeChallenge !== null) {
            $resendAvailableAt = $this->toCarbon($activeChallenge->resend_available_at);
            if ($resendAvailableAt !== null && $resendAvailableAt->isFuture()) {
                throw new PhoneOtpCooldownException(max(1, (int) ceil($now->diffInSeconds($resendAvailableAt))));
            }

            $activeChallenge->status = PhoneOtpChallenge::STATUS_SUPERSEDED;
            $activeChallenge->save();
        }

        $code = $this->generateOtpCode();
        $expiresAt = $now->copy()->addMinutes($settings->ttlMinutes);
        $resendAvailableAt = $now->copy()->addSeconds($settings->resendCooldownSeconds);

        $challenge = PhoneOtpChallenge::create([
            'phone' => $phone,
            'phone_hash' => $this->phoneNormalizer->hash($phone),
            'code_hash' => Hash::make($code),
            'status' => PhoneOtpChallenge::STATUS_PENDING,
            'delivery_channel' => $settings->channel,
            'delivery_webhook_url' => $settings->webhookUrl,
            'expires_at' => $expiresAt,
            'resend_available_at' => $resendAvailableAt,
            'attempts' => 0,
            'max_attempts' => $settings->maxAttempts,
            'device_name' => $payload['device_name'] ?? null,
            'requested_at' => $now,
        ]);

        DeliverPhoneOtpWebhookJob::dispatch(
            $settings->webhookUrl,
            $settings->channel,
            $phone,
            $code,
            (string) $challenge->_id,
            $expiresAt->toISOString(),
        );

        return new PhoneOtpChallengeResult(
            challengeId: (string) $challenge->_id,
            phone: $phone,
            channel: $settings->channel,
            expiresAt: $expiresAt,
            resendAvailableAt: $resendAvailableAt,
        );
    }

    /**
     * @param  array{
     *   challenge_id:string,
     *   phone:string,
     *   code:string,
     *   device_name?:string|null,
     *   anonymous_user_ids?:array<int, string>
     * }  $payload
     *
     * @throws ConcurrencyConflictException
     */
    public function verify(Tenant $tenant, array $payload): PhoneOtpVerificationResult
    {
        $this->assertPhoneOtpEnabled();

        $phone = $this->phoneNormalizer->normalize($payload['phone']);
        $challenge = $this->findChallenge((string) $payload['challenge_id']);
        $now = Carbon::now();

        if ($challenge === null || $challenge->phone !== $phone) {
            throw ValidationException::withMessages([
                'code' => ['The OTP challenge could not be verified.'],
            ]);
        }

        if ($challenge->status !== PhoneOtpChallenge::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'code' => ['The OTP challenge is no longer active.'],
            ]);
        }

        $expiresAt = $this->toCarbon($challenge->expires_at);
        if ($expiresAt === null || $expiresAt->lessThanOrEqualTo($now)) {
            $challenge->status = PhoneOtpChallenge::STATUS_EXPIRED;
            $challenge->save();

            throw ValidationException::withMessages([
                'code' => ['The OTP challenge has expired.'],
            ]);
        }

        if (! Hash::check((string) $payload['code'], (string) $challenge->code_hash)) {
            $challenge->attempts = ((int) ($challenge->attempts ?? 0)) + 1;
            if ($challenge->attempts >= (int) ($challenge->max_attempts ?? 5)) {
                $challenge->status = PhoneOtpChallenge::STATUS_LOCKED;
            }
            $challenge->save();

            throw ValidationException::withMessages([
                'code' => ['The OTP code is invalid.'],
            ]);
        }

        $user = $this->findOrCreateVerifiedPhoneUser($phone, $now);
        $mergedAnonymousUserIds = $this->mergeAnonymousUsers(
            $tenant,
            $user,
            $payload['anonymous_user_ids'] ?? []
        );

        $challenge->status = PhoneOtpChallenge::STATUS_VERIFIED;
        $challenge->verified_at = $now;
        $challenge->save();

        $this->profileBootstrapper->ensurePersonalAccount($user);
        $user->refresh();

        $token = $this->tenantScopedAccessTokenService->issueForAccountUser(
            $user,
            $this->tokenName($payload['device_name'] ?? null),
            $this->sanitizeAbilities($this->resolveAbilities($user)),
            (string) $tenant->_id,
        );

        return new PhoneOtpVerificationResult(
            user: $user->fresh(),
            plainTextToken: $token->plainTextToken,
            mergedAnonymousUserIds: $mergedAnonymousUserIds,
        );
    }

    private function assertPhoneOtpEnabled(): void
    {
        $governance = $this->authMethodResolver->currentGovernance();
        if (in_array('phone_otp', $governance['effective_methods'], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'auth_method' => ['Phone OTP is not enabled for this tenant.'],
        ]);
    }

    private function activeChallengeForPhone(string $phone, Carbon $now): ?PhoneOtpChallenge
    {
        /** @var PhoneOtpChallenge|null $challenge */
        $challenge = PhoneOtpChallenge::query()
            ->where('phone', $phone)
            ->where('status', PhoneOtpChallenge::STATUS_PENDING)
            ->where('expires_at', '>', $now)
            ->orderByDesc('created_at')
            ->first();

        return $challenge;
    }

    private function findChallenge(string $id): ?PhoneOtpChallenge
    {
        try {
            $id = (string) new ObjectId($id);
        } catch (\Throwable) {
            return null;
        }

        /** @var PhoneOtpChallenge|null $challenge */
        $challenge = PhoneOtpChallenge::query()->find($id);

        return $challenge;
    }

    private function findOrCreateVerifiedPhoneUser(string $phone, Carbon $now): AccountUser
    {
        /** @var AccountUser|null $user */
        $user = AccountUser::query()
            ->where('phones', 'all', [$phone])
            ->first();

        if ($user === null) {
            $user = AccountUser::create([
                'identity_state' => 'registered',
                'registered_at' => $now,
                'name' => $phone,
                'phones' => [$phone],
                'credentials' => [],
            ]);
        } else {
            $phones = collect((array) ($user->phones ?? []))
                ->map(static fn (mixed $value): string => trim((string) $value))
                ->filter(static fn (string $value): bool => $value !== '')
                ->unique()
                ->values()
                ->all();

            if (! in_array($phone, $phones, true)) {
                $phones[] = $phone;
            }

            $user->phones = array_values($phones);
            $user->identity_state = 'registered';
            $user->registered_at ??= $now;
            $user->save();
        }

        $user->syncCredential('phone_otp', $phone, null, [
            'verified_at' => $now->toISOString(),
        ]);

        return $user->fresh();
    }

    /**
     * @param  array<int, string>  $anonymousUserIds
     * @return array<int, string>
     *
     * @throws ConcurrencyConflictException
     */
    private function mergeAnonymousUsers(Tenant $tenant, AccountUser $user, array $anonymousUserIds): array
    {
        $ids = Collection::make($anonymousUserIds)
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $tenant->makeCurrent();
        $anonymousUsers = $ids->map(function (string $id): AccountUser {
            try {
                $objectId = new ObjectId($id);
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'anonymous_user_ids' => ['One or more anonymous identities was not a valid ObjectId String.'],
                ]);
            }

            $anonymousUser = AccountUser::query()->find($objectId);

            if ($anonymousUser === null) {
                throw ValidationException::withMessages([
                    'anonymous_user_ids' => ['One or more anonymous identities could not be found.'],
                ]);
            }

            if ($anonymousUser->identity_state !== 'anonymous') {
                throw ValidationException::withMessages([
                    'anonymous_user_ids' => ['Only anonymous identities can be merged during phone verification.'],
                ]);
            }

            return $anonymousUser;
        });

        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->identityMerger->merge($user, $anonymousUsers, (string) $user->_id, 'phone_otp_verified');

                return $ids->all();
            } catch (ConcurrencyConflictException $exception) {
                if ($attempt === $maxAttempts) {
                    throw $exception;
                }

                usleep(100_000);
            }
        }

        return $ids->all();
    }

    /**
     * @return array<int, string>
     */
    private function resolveAbilities(AccountUser $user): array
    {
        $account = Account::current();
        if (! $account) {
            $accessIds = $user->getAccessToIds();
            if ($accessIds !== []) {
                $account = Account::query()
                    ->whereIn('_id', $accessIds)
                    ->first();
            }
        }

        try {
            return $account ? $user->getPermissions($account) : $user->getPermissions();
        } catch (AuthenticationException) {
            return [];
        }
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     */
    private function sanitizeAbilities(array $abilities): array
    {
        if (in_array('*', $abilities, true)) {
            return AbilityCatalog::all();
        }

        return $abilities;
    }

    private function tokenName(?string $deviceName): string
    {
        $name = is_string($deviceName) ? trim($deviceName) : '';

        return $name === '' ? 'auth:phone-otp' : $name;
    }

    private function generateOtpCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
