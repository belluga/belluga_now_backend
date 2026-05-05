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
use App\Models\Tenants\TenantSettings;
use App\Support\Auth\AbilityCatalog;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

class TenantPhoneOtpAuthService
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNormalizer,
        private readonly PhoneOtpDeliverySettingsResolver $deliverySettings,
        private readonly TenantPublicAuthMethodResolver $authMethodResolver,
        private readonly PhoneOtpChallengeStore $challengeStore,
        private readonly PhoneOtpReviewAccessCodeHasher $reviewAccessCodeHasher,
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

        $phone = $this->phoneNormalizer->normalize($payload['phone']);
        $reviewAccess = $this->reviewAccessForPhone($phone);
        $settings = $reviewAccess !== null
            ? $this->reviewChallengeSettings($payload)
            : $this->deliverySettings->resolve($payload['delivery_channel'] ?? null);
        $now = Carbon::now();

        $code = $this->generateOtpCode();
        $expiresAt = $now->copy()->addMinutes($settings->ttlMinutes);
        $resendAvailableAt = $now->copy()->addSeconds($settings->resendCooldownSeconds);

        $challenge = $this->challengeStore->issue(
            phone: $phone,
            phoneHash: $this->phoneNormalizer->hash($phone),
            codeHash: Hash::make($code),
            deliveryChannel: $settings->channel,
            deliveryWebhookUrl: $settings->webhookUrl,
            expiresAt: $expiresAt,
            resendAvailableAt: $resendAvailableAt,
            maxAttempts: $settings->maxAttempts,
            deviceName: $payload['device_name'] ?? null,
            now: $now,
        );

        if (! $this->shouldBypassReviewDelivery($reviewAccess)) {
            DeliverPhoneOtpWebhookJob::dispatch(
                (string) $settings->webhookUrl,
                $settings->channel,
                $phone,
                $code,
                (string) $challenge->_id,
                $expiresAt->toISOString(),
            );
        }

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
            $this->challengeStore->markExpiredIfPending((string) $challenge->_id, $now);

            throw ValidationException::withMessages([
                'code' => ['The OTP challenge has expired.'],
            ]);
        }

        $reviewAccess = $this->reviewAccessForPhone($phone);
        $reviewCodeMatched = $reviewAccess !== null
            && $this->reviewAccessCodeHasher->check((string) $payload['code'], $reviewAccess['code_hash'] ?? null);

        if (! $reviewCodeMatched && ! Hash::check((string) $payload['code'], (string) $challenge->code_hash)) {
            $this->challengeStore->recordInvalidAttempt(
                challengeId: (string) $challenge->_id,
                phone: $phone,
                maxAttempts: (int) ($challenge->max_attempts ?? 5),
                now: $now,
            );

            throw ValidationException::withMessages([
                'code' => ['The OTP code is invalid.'],
            ]);
        }

        $this->assertPhoneUserCanAuthenticate($phone, $reviewCodeMatched);

        if (! $this->challengeStore->consumePending((string) $challenge->_id, $phone, $now)) {
            throw ValidationException::withMessages([
                'code' => ['The OTP challenge is no longer active.'],
            ]);
        }

        $user = $this->findOrCreateVerifiedPhoneUser($phone, $now);
        $mergedAnonymousUserIds = $this->mergeAnonymousUsers(
            $tenant,
            $user,
            $payload['anonymous_user_ids'] ?? []
        );

        $this->profileBootstrapper->ensurePersonalAccount($user);
        $user->refresh();
        $this->rematchExistingContactImports($user);

        $token = $this->tenantScopedAccessTokenService->issueForAccountUser(
            $user,
            $this->tokenName($payload['device_name'] ?? null),
            $this->sanitizeAbilities($this->resolveAbilities($user)),
            (string) $tenant->_id,
        );

        if ($reviewCodeMatched) {
            Log::info('Phone OTP review access authenticated.', [
                'tenant_id' => (string) ($tenant->_id ?? ''),
                'challenge_id' => (string) $challenge->_id,
                'phone_hash' => $this->phoneNormalizer->hash($phone),
                'user_id' => (string) ($user->_id ?? ''),
            ]);
        }

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
        /** @var AccountUser|null $existing */
        $existing = AccountUser::withTrashed()
            ->where('phones', 'all', [$phone])
            ->first();

        if ($existing !== null && ! $existing->isActive()) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number cannot be used to authenticate.'],
            ]);
        }

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

    private function rematchExistingContactImports(AccountUser $user): void
    {
        $userId = (string) ($user->_id ?? $user->getKey() ?? '');
        if ($userId === '') {
            return;
        }

        $phoneHashes = collect((array) ($user->phone_hashes ?? []))
            ->map(static fn (mixed $hash): string => trim((string) $hash))
            ->filter(static fn (string $hash): bool => $hash !== '')
            ->unique()
            ->values()
            ->all();

        if ($phoneHashes === []) {
            return;
        }

        ContactHashDirectory::query()
            ->where('type', 'phone')
            ->whereIn('contact_hash', $phoneHashes)
            ->update([
                'matched_user_id' => $userId,
                'match_snapshot' => [
                    'display_name' => trim((string) ($user->name ?? '')) ?: null,
                    'avatar_url' => null,
                ],
                'updated_at' => Carbon::now(),
            ]);
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
     * @return array{phone_e164:string,code_hash:string}|null
     */
    private function reviewAccessForPhone(string $phone): ?array
    {
        $settings = TenantSettings::current()?->getAttribute('phone_otp_review_access');
        if (! is_array($settings)) {
            return null;
        }

        $configuredPhone = trim((string) ($settings['phone_e164'] ?? ''));
        $codeHash = trim((string) ($settings['code_hash'] ?? ''));
        if ($configuredPhone === '' || $codeHash === '') {
            return null;
        }

        try {
            $normalizedConfiguredPhone = $this->phoneNormalizer->normalize($configuredPhone);
        } catch (\Throwable) {
            return null;
        }

        if ($normalizedConfiguredPhone !== $phone) {
            return null;
        }

        return [
            'phone_e164' => $normalizedConfiguredPhone,
            'code_hash' => $codeHash,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reviewChallengeSettings(array $payload): PhoneOtpDeliverySettings
    {
        $settings = TenantSettings::current()?->getAttribute('outbound_integrations');
        $otp = is_array($settings) && is_array($settings['otp'] ?? null) ? $settings['otp'] : [];
        $requestedChannel = trim((string) ($payload['delivery_channel'] ?? ''));
        $channel = in_array($requestedChannel, ['whatsapp', 'sms'], true) ? $requestedChannel : 'whatsapp';

        return new PhoneOtpDeliverySettings(
            webhookUrl: '',
            channel: $channel,
            ttlMinutes: max(1, (int) ($otp['ttl_minutes'] ?? 10)),
            resendCooldownSeconds: max(1, (int) ($otp['resend_cooldown_seconds'] ?? 60)),
            maxAttempts: max(1, (int) ($otp['max_attempts'] ?? 5)),
        );
    }

    /**
     * @param  array<string, string>|null  $reviewAccess
     */
    private function shouldBypassReviewDelivery(?array $reviewAccess): bool
    {
        return $reviewAccess !== null;
    }

    private function assertPhoneUserCanAuthenticate(string $phone, bool $reviewCodeMatched): void
    {
        /** @var AccountUser|null $existing */
        $existing = AccountUser::withTrashed()
            ->where('phones', 'all', [$phone])
            ->first();

        if ($existing === null || $existing->isActive()) {
            return;
        }

        if ($reviewCodeMatched) {
            Log::warning('Phone OTP review access denied for disabled user.', [
                'phone_hash' => $this->phoneNormalizer->hash($phone),
                'user_id' => (string) ($existing->_id ?? ''),
            ]);
        }

        throw ValidationException::withMessages([
            'phone' => ['This phone number cannot be used to authenticate.'],
        ]);
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
