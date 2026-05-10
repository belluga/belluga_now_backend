<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Events\Auth\PasswordResetTokenIssued;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

class PasswordResetTokenService
{
    public const TENANT_USERS_BROKER = 'tenant_users';

    public const LANDLORD_USERS_BROKER = 'landlord_users';

    private const ISSUE_COOLDOWN_CACHE_PREFIX = 'password_reset_tokens:cooldown';

    /**
     * @var array<string, string>
     */
    private static array $testingIssuedTokens = [];

    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function issueForUser(mixed $userId, string $email, string $broker, ?string $scope = null): string
    {
        $token = bin2hex(random_bytes(32));
        $now = CarbonImmutable::now('UTC');
        $scopeKey = $this->scopeKey($scope);
        $slotKey = $this->slotKey($userId, $broker, $scope);

        $this->collection()->updateOne(
            ['slot_key' => $slotKey],
            [
                '$set' => [
                    'slot_key' => $slotKey,
                    'scope_key' => $scopeKey,
                    'broker' => $broker,
                    'user_id' => $userId,
                    'user_id_string' => trim((string) $userId),
                    'email' => strtolower($email),
                    'token_hash' => Hash::make($token),
                    'token_lookup_hash' => $this->lookupHash($token),
                    'updated_at' => $this->toUtcDateTime($now),
                    'expires_at' => $this->toUtcDateTime($now->addMinutes($this->expiryMinutes($broker))),
                ],
                '$setOnInsert' => [
                    'created_at' => $this->toUtcDateTime($now),
                ],
            ],
            ['upsert' => true],
        );

        $this->events->dispatch(new PasswordResetTokenIssued(
            broker: $broker,
            email: strtolower($email),
            userId: trim((string) $userId),
            token: $token,
        ));

        if (app()->environment('testing')) {
            self::$testingIssuedTokens[$this->testingProbeKey($userId, $broker, $scope)] = $token;
        }

        return $token;
    }

    public function consumeForUser(mixed $userId, string $token, string $broker, ?string $scope = null): void
    {
        if (! $this->attemptConsumeForUser($userId, $token, $broker, $scope)) {
            $this->throwInvalidToken();
        }
    }

    public function attemptConsumeForUser(mixed $userId, string $token, string $broker, ?string $scope = null): bool
    {
        $deleted = $this->collection()->deleteOne([
            'slot_key' => $this->slotKey($userId, $broker, $scope),
            'token_lookup_hash' => $this->lookupHash($token),
            'expires_at' => ['$gt' => $this->toUtcDateTime(CarbonImmutable::now('UTC'))],
        ]);

        return $deleted->getDeletedCount() === 1;
    }

    private function expiryMinutes(string $broker): int
    {
        return max(1, (int) config("auth.passwords.{$broker}.expire", 60));
    }

    public function rejectInvalidResetAttempt(string $token, string $password, string $broker, ?string $scope = null): never
    {
        $this->absorbMissingResetWorkFactor($token, $password, $broker, $scope);
        $this->throwInvalidToken();
    }

    public function absorbMissingResetWorkFactor(string $token, string $password, string $broker, ?string $scope = null): void
    {
        $probe = $this->lookupHash($token).':'.$broker.':'.$this->scopeKey($scope);
        $hash = Hash::make($probe);
        Hash::check($probe, $hash);
        Hash::make($password);
    }

    private function throwInvalidToken(): never
    {
        throw ValidationException::withMessages([
            'reset_token' => 'Invalid token',
        ]);
    }

    public function acquireIssueCooldown(string $email, string $broker, ?string $scope = null): bool
    {
        return Cache::add(
            $this->cooldownKeyForEmail($email, $broker, $scope),
            true,
            $this->issueCooldownSeconds($broker),
        );
    }

    public function acquireIssueCooldownForUser(mixed $userId, string $broker, ?string $scope = null): bool
    {
        return Cache::add(
            $this->cooldownKeyForUser($userId, $broker, $scope),
            true,
            $this->issueCooldownSeconds($broker),
        );
    }

    public function releaseIssueCooldownForUser(mixed $userId, string $broker, ?string $scope = null): void
    {
        Cache::forget($this->cooldownKeyForUser($userId, $broker, $scope));
    }

    public function absorbMissingIssueWorkFactor(string $email, string $broker, ?string $scope = null): void
    {
        $probe = $this->identifierHash(strtolower($email), $broker, $scope);
        $hash = Hash::make($probe);
        Hash::check($probe, $hash);
    }

    public function latestIssuedTokenForTesting(mixed $userId, string $broker, ?string $scope = null): ?string
    {
        if (! app()->environment('testing')) {
            return null;
        }

        return self::$testingIssuedTokens[$this->testingProbeKey($userId, $broker, $scope)] ?? null;
    }

    private function testingProbeKey(mixed $userId, string $broker, ?string $scope = null): string
    {
        return sprintf('%s:%s:%s', $this->scopeKey($scope), $broker, trim((string) $userId));
    }

    private function slotKey(mixed $userId, string $broker, ?string $scope = null): string
    {
        return sprintf('%s:%s:%s', $this->scopeKey($scope), $broker, trim((string) $userId));
    }

    private function lookupHash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->securitySalt());
    }

    private function cooldownKeyForEmail(string $email, string $broker, ?string $scope = null): string
    {
        return $this->cooldownKeyFromIdentifier(strtolower($email), $broker, $scope);
    }

    private function cooldownKeyForUser(mixed $userId, string $broker, ?string $scope = null): string
    {
        return $this->cooldownKeyFromIdentifier('user:'.trim((string) $userId), $broker, $scope);
    }

    private function cooldownKeyFromIdentifier(string $identifier, string $broker, ?string $scope = null): string
    {
        return sprintf(
            '%s:%s',
            self::ISSUE_COOLDOWN_CACHE_PREFIX,
            $this->identifierHash($identifier, $broker, $scope),
        );
    }

    private function identifierHash(string $value, string $broker, ?string $scope = null): string
    {
        return hash_hmac('sha256', $this->scopeKey($scope).'|'.$broker.'|'.$value, $this->securitySalt());
    }

    private function scopeKey(?string $scope): string
    {
        $normalized = trim((string) $scope);

        return $normalized !== '' ? $normalized : 'global';
    }

    private function issueCooldownSeconds(string $broker): int
    {
        return max(1, (int) config("auth.passwords.{$broker}.throttle", 60));
    }

    private function securitySalt(): string
    {
        return (string) config('app.key', 'password-reset-token-service');
    }

    private function toUtcDateTime(CarbonImmutable $value): UTCDateTime
    {
        return new UTCDateTime($value->getTimestampMs());
    }

    private function collection(): Collection
    {
        return DB::connection('landlord')->getMongoDB()->selectCollection('password_reset_tokens');
    }

}
