<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Security\ApiAbuseSignalRecorder;
use App\Models\Landlord\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApiSecurityHardening
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isApiPath($request)) {
            return $next($request);
        }

        $correlationId = $this->resolveCorrelationId($request);
        $cfRayId = $this->resolveCfRayId($request);
        $observeMode = $this->isObserveMode();
        $tenantReference = $this->resolveTenantReference($request);
        $identity = $this->resolvePrincipalIdentity($request);
        $profile = $this->resolveProfile($request, $tenantReference);

        Log::withContext([
            'correlation_id' => $correlationId,
            'cf_ray_id' => $cfRayId,
            'tenant_reference' => $tenantReference,
            'api_security_level' => (string) $profile['level'],
            'api_security_level_source' => (string) $profile['level_source'],
            'api_security_observe_mode' => $observeMode,
        ]);

        if ($this->hasSpoofedClientIpHeader($request)) {
            $spoofed = $this->handleViolation(
                request: $request,
                profile: $profile,
                identity: $identity,
                tenantReference: $tenantReference,
                code: 'spoofed_client_ip_header',
                status: 403,
                message: 'Forwarded client IP headers are not trusted for this request path.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                observeMode: $observeMode
            );
            if ($spoofed !== null) {
                return $spoofed;
            }
        }

        if ($this->shouldEnforceCloudflareOriginLock() && ! $this->isCloudflareRequest($request)) {
            $originDenied = $this->handleViolation(
                request: $request,
                profile: $profile,
                identity: $identity,
                tenantReference: $tenantReference,
                code: 'origin_access_denied',
                status: 403,
                message: 'Direct origin access is not allowed.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                observeMode: $observeMode
            );
            if ($originDenied !== null) {
                return $originDenied;
            }
        }

        $lifecycleGate = $this->enforceLifecycleGate(
            request: $request,
            profile: $profile,
            identity: $identity,
            tenantReference: $tenantReference,
            correlationId: $correlationId,
            cfRayId: $cfRayId,
            observeMode: $observeMode
        );
        if ($lifecycleGate !== null) {
            return $lifecycleGate;
        }

        $rateLimited = $this->enforceRateLimit(
            request: $request,
            profile: $profile,
            identity: $identity,
            tenantReference: $tenantReference,
            correlationId: $correlationId,
            cfRayId: $cfRayId,
            observeMode: $observeMode
        );
        if ($rateLimited !== null) {
            return $rateLimited;
        }

        $idempotencyContext = null;
        if ($this->isUnsafeMethod($request) && (bool) ($profile['require_idempotency'] ?? false)) {
            $idempotencyResult = $this->enforceIdempotency(
                request: $request,
                profile: $profile,
                identity: $identity,
                tenantReference: $tenantReference,
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                observeMode: $observeMode
            );
            if ($idempotencyResult instanceof Response) {
                return $idempotencyResult;
            }
            if (is_array($idempotencyResult)) {
                $idempotencyContext = $idempotencyResult;
            }
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($idempotencyContext !== null) {
                Cache::forget((string) $idempotencyContext['cache_key']);
            }

            throw $exception;
        }

        if ($idempotencyContext !== null) {
            $this->storeIdempotencyResponse($response, $idempotencyContext);
        }

        if ($this->isLifecycleWarningActive($identity)) {
            $response->headers->set('X-Api-Security-Warn', 'true');
        }

        return $this->withSecurityHeaders($response, $correlationId, $cfRayId, $profile);
    }

    private function isApiPath(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        return str_starts_with($path, 'api/') || str_starts_with($path, 'admin/api/');
    }

    private function resolveCorrelationId(Request $request): string
    {
        $candidate = trim((string) ($request->header('X-Correlation-Id') ?: $request->header('X-Request-Id')));

        return $candidate !== '' ? $candidate : (string) Str::uuid();
    }

    private function resolveCfRayId(Request $request): ?string
    {
        $cfRay = trim((string) $request->header('CF-Ray'));

        return $cfRay !== '' ? $cfRay : null;
    }

    private function shouldEnforceCloudflareOriginLock(): bool
    {
        return (bool) config('api_security.cloudflare.enforce_origin_lock', false);
    }

    private function isObserveMode(): bool
    {
        return (bool) config('api_security.observe_mode', false);
    }

    private function isCloudflareRequest(Request $request): bool
    {
        $cfRay = trim((string) $request->header('CF-Ray'));
        if ($cfRay === '') {
            return false;
        }

        if ($this->requiresTrustedProxyForForwardedHeaders() && ! $this->isRequestFromTrustedProxy($request)) {
            return false;
        }

        /** @var list<string> $headers */
        $headers = (array) config('api_security.cloudflare.presence_headers', ['CF-Ray', 'CF-Connecting-IP']);
        foreach ($headers as $header) {
            $value = trim((string) $request->header($header));
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    private function requiresTrustedProxyForForwardedHeaders(): bool
    {
        return (bool) config('api_security.cloudflare.require_trusted_proxy_for_forwarded_headers', true);
    }

    private function hasSpoofedClientIpHeader(Request $request): bool
    {
        if (! $this->requiresTrustedProxyForForwardedHeaders()) {
            return false;
        }

        $hasForwardedHeaders = trim((string) $request->header('CF-Connecting-IP')) !== ''
            || trim((string) $request->header('X-Forwarded-For')) !== '';

        if (! $hasForwardedHeaders) {
            return false;
        }

        return ! $this->isRequestFromTrustedProxy($request);
    }

    private function isRequestFromTrustedProxy(Request $request): bool
    {
        $remoteAddr = trim((string) $request->server('REMOTE_ADDR', ''));
        if ($remoteAddr === '') {
            return false;
        }

        $trustedProxies = $this->trustedProxyRanges();
        if ($trustedProxies === []) {
            return false;
        }

        foreach ($trustedProxies as $proxyRange) {
            if ($proxyRange === '*') {
                return true;
            }

            if (IpUtils::checkIp($remoteAddr, $proxyRange)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function trustedProxyRanges(): array
    {
        $raw = trim((string) env('TRUSTED_PROXIES', ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @return array{
     *     level:string,
     *     label:string,
     *     requests_per_minute:int,
     *     require_idempotency:bool,
     *     replay_window_seconds:int,
     *     level_source:string
     * }
     */
    private function resolveProfile(Request $request, ?string $tenantReference): array
    {
        $path = ltrim($request->path(), '/');
        $method = strtoupper($request->method());

        $minimumLevel = $this->normalizeLevel((string) config('api_security.minimum_level', 'L1'));
        $systemDefault = $this->normalizeLevel($this->resolveDefaultLevel($path, $method));
        $base = $this->buildProfileFromLevel($this->strongerLevel($systemDefault, $minimumLevel));
        $base['level_source'] = 'system_default';

        $tenantOverride = $this->resolveTenantOverride($tenantReference);
        if ($tenantOverride !== null) {
            $base = $this->mergeMonotonicProfile($base, $tenantOverride, 'tenant_override');
        }

        /** @var array<int,array<string,mixed>> $overrides */
        $overrides = (array) config('api_security.route_overrides', []);
        foreach ($overrides as $override) {
            $pattern = (string) ($override['pattern'] ?? '');
            if ($pattern === '') {
                continue;
            }

            if (@preg_match($pattern, $path) !== 1) {
                continue;
            }

            $allowedMethods = array_values(array_map(
                static fn (mixed $entry): string => strtoupper((string) $entry),
                (array) ($override['methods'] ?? [])
            ));
            if ($allowedMethods !== [] && ! in_array($method, $allowedMethods, true)) {
                continue;
            }

            $base = $this->mergeMonotonicProfile($base, $override, 'endpoint_override');
            break;
        }

        $base = $this->mergeMonotonicProfile(
            $base,
            ['level' => $minimumLevel],
            $base['level_source']
        );

        return $base;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array{level:string,label:string,requests_per_minute:int,require_idempotency:bool,replay_window_seconds:int,level_source:string}  $current
     * @return array{level:string,label:string,requests_per_minute:int,require_idempotency:bool,replay_window_seconds:int,level_source:string}
     */
    private function mergeMonotonicProfile(array $current, array $candidate, string $source): array
    {
        $candidateLevel = $this->normalizeLevel((string) ($candidate['level'] ?? $current['level']));
        $effectiveLevel = $this->strongerLevel((string) $current['level'], $candidateLevel);
        $effectiveByLevel = $this->buildProfileFromLevel($effectiveLevel);

        $rpmCandidates = [
            (int) $current['requests_per_minute'],
            (int) $effectiveByLevel['requests_per_minute'],
        ];
        if (array_key_exists('requests_per_minute', $candidate)) {
            $rpmCandidates[] = max(1, (int) $candidate['requests_per_minute']);
        }

        $windowCandidates = [
            (int) $current['replay_window_seconds'],
            (int) $effectiveByLevel['replay_window_seconds'],
        ];
        if (array_key_exists('replay_window_seconds', $candidate)) {
            $windowCandidates[] = max(30, (int) $candidate['replay_window_seconds']);
        }

        $candidateRequiresIdempotency = array_key_exists('require_idempotency', $candidate)
            ? (bool) $candidate['require_idempotency']
            : (bool) $effectiveByLevel['require_idempotency'];

        $sourceForLevel = $current['level_source'];
        if ($this->levelRank($candidateLevel) > $this->levelRank((string) $current['level'])) {
            $sourceForLevel = $source;
        }

        return [
            'level' => $effectiveLevel,
            'label' => (string) $effectiveByLevel['label'],
            'requests_per_minute' => max(1, min($rpmCandidates)),
            'require_idempotency' => (bool) $current['require_idempotency'] || $candidateRequiresIdempotency,
            'replay_window_seconds' => max($windowCandidates),
            'level_source' => $sourceForLevel,
        ];
    }

    /**
     * @return array{level:string,label:string,requests_per_minute:int,require_idempotency:bool,replay_window_seconds:int}
     */
    private function buildProfileFromLevel(string $level): array
    {
        $levels = (array) config('api_security.levels', []);
        $fallback = (array) ($levels[$level] ?? $levels['L2'] ?? []);

        return [
            'level' => $level,
            'label' => (string) ($fallback['label'] ?? $level),
            'requests_per_minute' => max(1, (int) ($fallback['requests_per_minute'] ?? 300)),
            'require_idempotency' => (bool) ($fallback['require_idempotency'] ?? false),
            'replay_window_seconds' => max(30, (int) ($fallback['replay_window_seconds'] ?? 600)),
        ];
    }

    private function resolveDefaultLevel(string $path, string $method): string
    {
        if (str_starts_with($path, 'admin/api/')) {
            return 'L2';
        }

        if (str_starts_with($path, 'api/')) {
            return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) ? 'L1' : (string) config('api_security.default_level', 'L2');
        }

        return 'L1';
    }

    private function normalizeLevel(string $level): string
    {
        return match (strtoupper(trim($level))) {
            'L1' => 'L1',
            'L3' => 'L3',
            default => 'L2',
        };
    }

    private function strongerLevel(string $left, string $right): string
    {
        return $this->levelRank($left) >= $this->levelRank($right)
            ? $this->normalizeLevel($left)
            : $this->normalizeLevel($right);
    }

    private function levelRank(string $level): int
    {
        $rank = (array) config('api_security.level_rank', ['L1' => 1, 'L2' => 2, 'L3' => 3]);

        return (int) ($rank[$this->normalizeLevel($level)] ?? 2);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveTenantOverride(?string $tenantReference): ?array
    {
        if (! (bool) config('api_security.tenant_overrides.enabled', true)) {
            return null;
        }

        if ($tenantReference === null || $tenantReference === '') {
            return null;
        }

        $overrides = (array) config('api_security.tenant_overrides.tenants', []);
        $override = $overrides[$tenantReference] ?? null;
        if ($override === null) {
            return null;
        }

        if (is_string($override)) {
            return ['level' => $override];
        }

        return is_array($override) ? $override : null;
    }

    private function isUnsafeMethod(Request $request): bool
    {
        return ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function enforceRateLimit(
        Request $request,
        array $profile,
        string $identity,
        ?string $tenantReference,
        string $correlationId,
        ?string $cfRayId,
        bool $observeMode,
    ): ?Response {
        $level = (string) $profile['level'];
        $maxAttempts = (int) $profile['requests_per_minute'];
        $windowSeconds = max(1, (int) config('api_security.rate_limit.window_seconds', 60));
        $prefix = (string) config('api_security.rate_limit.cache_prefix', 'api_security:rate');
        $key = sprintf('%s:%s:%s', $prefix, strtolower($level), $identity);

        try {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $retryAfter = max(1, RateLimiter::availableIn($key));

                return $this->handleViolation(
                    request: $request,
                    profile: $profile,
                    identity: $identity,
                    tenantReference: $tenantReference,
                    code: 'rate_limited',
                    status: 429,
                    message: 'Too many requests. Retry later.',
                    correlationId: $correlationId,
                    cfRayId: $cfRayId,
                    observeMode: $observeMode,
                    retryAfter: $retryAfter
                );
            }

            RateLimiter::hit($key, $windowSeconds);
        } catch (Throwable $exception) {
            Log::error('API security rate limiter backend failed.', [
                'code' => 'rate_limiter_backend_error',
                'path' => '/'.ltrim($request->path(), '/'),
                'method' => strtoupper($request->method()),
                'level' => $level,
                'correlation_id' => $correlationId,
                'cf_ray_id' => $cfRayId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            if ((bool) config('api_security.rate_limit.fail_closed_on_backend_error', false) && ! $observeMode) {
                return $this->buildErrorResponse(
                    status: 503,
                    code: 'rate_limit_unavailable',
                    message: 'Rate limiting is temporarily unavailable.',
                    correlationId: $correlationId,
                    cfRayId: $cfRayId,
                    level: $level,
                    profile: $profile
                );
            }
        }

        return null;
    }

    /**
     * @return array{cache_key:string,fingerprint:string,replay_window_seconds:int}|Response|null
     */
    private function enforceIdempotency(
        Request $request,
        array $profile,
        string $identity,
        ?string $tenantReference,
        string $correlationId,
        ?string $cfRayId,
        bool $observeMode,
    ): Response|array|null {
        $idempotencyKey = $this->resolveIdempotencyKey($request);
        if ($idempotencyKey === null) {
            return $this->handleViolation(
                request: $request,
                profile: $profile,
                identity: $identity,
                tenantReference: $tenantReference,
                code: 'idempotency_missing',
                status: 422,
                message: 'Idempotency key is required for this endpoint.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                observeMode: $observeMode
            );
        }

        if (strlen($idempotencyKey) > 255 || ! preg_match('/^[A-Za-z0-9._:-]{8,255}$/', $idempotencyKey)) {
            return $this->handleViolation(
                request: $request,
                profile: $profile,
                identity: $identity,
                tenantReference: $tenantReference,
                code: 'idempotency_malformed',
                status: 422,
                message: 'Idempotency key format is invalid.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                observeMode: $observeMode
            );
        }

        $fingerprint = $this->buildRequestFingerprint($request, $identity);
        $prefix = (string) config('api_security.idempotency.cache_prefix', 'api_security:idempotency');
        $cacheKey = sprintf('%s:%s:%s:%s', $prefix, strtolower((string) $profile['level']), $identity, hash('sha256', $idempotencyKey));
        $replayWindowSeconds = max(30, (int) ($profile['replay_window_seconds'] ?? 600));

        $pendingPayload = [
            'fingerprint' => $fingerprint,
            'created_at' => time(),
        ];

        if (! Cache::add($cacheKey, $pendingPayload, $replayWindowSeconds)) {
            $existing = Cache::get($cacheKey);
            if (! is_array($existing)) {
                return $this->handleViolation(
                    request: $request,
                    profile: $profile,
                    identity: $identity,
                    tenantReference: $tenantReference,
                    code: 'idempotency_replayed',
                    status: 409,
                    message: 'Request is already being processed.',
                    correlationId: $correlationId,
                    cfRayId: $cfRayId,
                    observeMode: $observeMode
                );
            }

            $existingFingerprint = (string) ($existing['fingerprint'] ?? '');
            if ($existingFingerprint !== $fingerprint) {
                return $this->handleViolation(
                    request: $request,
                    profile: $profile,
                    identity: $identity,
                    tenantReference: $tenantReference,
                    code: 'idempotency_replayed',
                    status: 409,
                    message: 'Idempotency key was already used with a different payload.',
                    correlationId: $correlationId,
                    cfRayId: $cfRayId,
                    observeMode: $observeMode
                );
            }

            $cachedResponse = Arr::get($existing, 'response');
            if (is_array($cachedResponse)) {
                $replayedResponse = response(
                    (string) ($cachedResponse['body'] ?? ''),
                    (int) ($cachedResponse['status'] ?? 200),
                    (array) ($cachedResponse['headers'] ?? [])
                );

                $replayedResponse->headers->set('X-Idempotency-Replayed', 'true');

                return $this->withSecurityHeaders($replayedResponse, $correlationId, $cfRayId, $profile);
            }

            return $this->handleViolation(
                request: $request,
                profile: $profile,
                identity: $identity,
                tenantReference: $tenantReference,
                code: 'idempotency_replayed',
                status: 409,
                message: 'Request is already being processed.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                observeMode: $observeMode
            );
        }

        return [
            'cache_key' => $cacheKey,
            'fingerprint' => $fingerprint,
            'replay_window_seconds' => $replayWindowSeconds,
        ];
    }

    private function resolveIdempotencyKey(Request $request): ?string
    {
        /** @var list<string> $headerKeys */
        $headerKeys = (array) config('api_security.idempotency.header_keys', ['Idempotency-Key', 'X-Idempotency-Key']);
        foreach ($headerKeys as $headerKey) {
            $candidate = trim((string) $request->header($headerKey));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $bodyKey = (string) config('api_security.idempotency.body_key', 'idempotency_key');
        $bodyValue = trim((string) $request->input($bodyKey));

        return $bodyValue !== '' ? $bodyValue : null;
    }

    private function resolvePrincipalIdentity(Request $request): string
    {
        $principal = $request->user('sanctum');
        if ($principal !== null) {
            return 'user:'.(string) $principal->getAuthIdentifier();
        }

        $clientIp = $this->resolveClientIp($request);
        if ($clientIp !== null) {
            return 'ip:'.$clientIp;
        }

        return 'anon';
    }

    private function resolveClientIp(Request $request): ?string
    {
        $trustedForwarded = $this->requiresTrustedProxyForForwardedHeaders() && $this->isRequestFromTrustedProxy($request);
        if ($trustedForwarded) {
            $cfConnectingIp = trim((string) $request->header('CF-Connecting-IP'));
            if ($cfConnectingIp !== '' && filter_var($cfConnectingIp, FILTER_VALIDATE_IP) !== false) {
                return $cfConnectingIp;
            }
        }

        $ip = trim((string) $request->ip());
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return $ip;
        }

        return null;
    }

    private function buildRequestFingerprint(Request $request, string $identity): string
    {
        $payload = $request->all();
        $this->ksortRecursive($payload);

        return hash('sha256', implode('|', [
            strtoupper($request->method()),
            ltrim($request->path(), '/'),
            $identity,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function ksortRecursive(array &$payload): void
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    /**
     * @param  array{cache_key:string,fingerprint:string,replay_window_seconds:int}  $context
     */
    private function storeIdempotencyResponse(Response $response, array $context): void
    {
        $status = $response->getStatusCode();
        if ($status >= 500) {
            Cache::forget($context['cache_key']);

            return;
        }

        $content = (string) $response->getContent();
        $contentType = (string) $response->headers->get('Content-Type', '');
        $maxBytes = max(1024, (int) config('api_security.idempotency.cacheable_response_max_bytes', 131072));

        if ($content === '' || strlen($content) > $maxBytes || ! str_contains(strtolower($contentType), 'application/json')) {
            Cache::forget($context['cache_key']);

            return;
        }

        $current = Cache::get($context['cache_key']);
        if (! is_array($current)) {
            $current = ['fingerprint' => $context['fingerprint'], 'created_at' => time()];
        }

        $current['response'] = [
            'status' => $status,
            'headers' => [
                'Content-Type' => $contentType,
            ],
            'body' => $content,
        ];

        Cache::put($context['cache_key'], $current, max(30, (int) $context['replay_window_seconds']));
    }

    private function enforceLifecycleGate(
        Request $request,
        array $profile,
        string $identity,
        ?string $tenantReference,
        string $correlationId,
        ?string $cfRayId,
        bool $observeMode,
    ): ?Response {
        if (! (bool) config('api_security.lifecycle.enabled', true)) {
            return null;
        }

        $state = $this->getLifecycleState($identity);
        if ($state === null) {
            return null;
        }

        $now = time();
        $recoverAfter = max(60, (int) config('api_security.lifecycle.recover_after_seconds', 1800));
        $lastViolation = (int) ($state['last_violation_at'] ?? 0);
        if ($lastViolation > 0 && ($now - $lastViolation) > $recoverAfter) {
            Cache::forget($this->lifecycleCacheKey($identity));

            return null;
        }

        $hardUntil = (int) ($state['hard_block_until'] ?? 0);
        if ($hardUntil > $now) {
            return $this->handleViolation(
                request: $request,
                profile: $profile,
                identity: $identity,
                tenantReference: $tenantReference,
                code: 'hard_blocked',
                status: 403,
                message: 'Request temporarily blocked due to repeated abuse signals.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                observeMode: $observeMode,
                retryAfter: max(1, $hardUntil - $now)
            );
        }

        $softUntil = (int) ($state['soft_block_until'] ?? 0);
        if ($softUntil > $now) {
            return $this->handleViolation(
                request: $request,
                profile: $profile,
                identity: $identity,
                tenantReference: $tenantReference,
                code: 'soft_blocked',
                status: 429,
                message: 'Request temporarily blocked while abuse signals cool down.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                observeMode: $observeMode,
                retryAfter: max(1, $softUntil - $now)
            );
        }

        $challengeUntil = (int) ($state['challenge_until'] ?? 0);
        if ($challengeUntil > $now) {
            return $this->handleViolation(
                request: $request,
                profile: $profile,
                identity: $identity,
                tenantReference: $tenantReference,
                code: 'challenge_required',
                status: 403,
                message: 'Additional verification is required before retrying.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                observeMode: $observeMode,
                retryAfter: max(1, $challengeUntil - $now)
            );
        }

        return null;
    }

    private function isLifecycleWarningActive(string $identity): bool
    {
        if (! (bool) config('api_security.lifecycle.enabled', true)) {
            return false;
        }

        $state = $this->getLifecycleState($identity);
        if ($state === null) {
            return false;
        }

        $warnAfter = max(1, (int) config('api_security.lifecycle.warn_after', 1));
        $count = (int) ($state['count'] ?? 0);

        return $count >= $warnAfter;
    }

    /**
     * @return array{action:string,retry_after:?int,count:int}
     */
    private function registerLifecycleViolation(string $identity, string $code): array
    {
        if (! (bool) config('api_security.lifecycle.enabled', true)) {
            return [
                'action' => 'none',
                'retry_after' => null,
                'count' => 0,
            ];
        }

        $state = $this->getLifecycleState($identity) ?? [];
        $now = time();

        $count = max(0, (int) ($state['count'] ?? 0)) + 1;
        $state['count'] = $count;
        $state['first_violation_at'] = (int) ($state['first_violation_at'] ?? $now);
        $state['last_violation_at'] = $now;
        $state['last_code'] = $code;

        $warnAfter = max(1, (int) config('api_security.lifecycle.warn_after', 1));
        $challengeAfter = max($warnAfter, (int) config('api_security.lifecycle.challenge_after', 2));
        $softAfter = max($challengeAfter, (int) config('api_security.lifecycle.soft_block_after', 4));
        $hardAfter = max($softAfter, (int) config('api_security.lifecycle.hard_block_after', 8));

        $action = 'none';
        $retryAfter = null;

        if ($count >= $hardAfter) {
            $seconds = max(60, (int) config('api_security.lifecycle.hard_block_seconds', 900));
            $state['hard_block_until'] = $now + $seconds;
            $action = 'hard_block';
            $retryAfter = $seconds;
        } elseif ($count >= $softAfter) {
            $seconds = max(30, (int) config('api_security.lifecycle.soft_block_seconds', 180));
            $state['soft_block_until'] = $now + $seconds;
            $action = 'soft_block';
            $retryAfter = $seconds;
        } elseif ($count >= $challengeAfter) {
            $seconds = max(15, (int) config('api_security.lifecycle.challenge_seconds', 120));
            $state['challenge_until'] = $now + $seconds;
            $action = 'challenge';
            $retryAfter = $seconds;
        } elseif ($count >= $warnAfter) {
            $action = 'warn';
        }

        $ttl = max(
            60,
            (int) config('api_security.lifecycle.window_seconds', 900),
            (int) config('api_security.lifecycle.recover_after_seconds', 1800),
            (int) ($retryAfter ?? 0)
        );

        Cache::put($this->lifecycleCacheKey($identity), $state, $ttl);

        return [
            'action' => $action,
            'retry_after' => $retryAfter,
            'count' => $count,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getLifecycleState(string $identity): ?array
    {
        $state = Cache::get($this->lifecycleCacheKey($identity));

        return is_array($state) ? $state : null;
    }

    private function lifecycleCacheKey(string $identity): string
    {
        $prefix = (string) config('api_security.lifecycle.cache_prefix', 'api_security:lifecycle');

        return sprintf('%s:%s', $prefix, hash('sha256', $identity));
    }

    private function handleViolation(
        Request $request,
        array $profile,
        string $identity,
        ?string $tenantReference,
        string $code,
        int $status,
        string $message,
        string $correlationId,
        ?string $cfRayId,
        bool $observeMode,
        ?int $retryAfter = null,
    ): ?Response {
        $lifecycle = $this->registerLifecycleViolation($identity, $code);
        $resolvedCode = $code;
        $resolvedStatus = $status;
        $resolvedMessage = $message;
        $resolvedRetryAfter = $retryAfter;

        if ($lifecycle['action'] === 'challenge') {
            $resolvedCode = 'challenge_required';
            $resolvedStatus = 403;
            $resolvedMessage = 'Additional verification is required before retrying.';
            $resolvedRetryAfter = $lifecycle['retry_after'];
        } elseif ($lifecycle['action'] === 'soft_block') {
            $resolvedCode = 'soft_blocked';
            $resolvedStatus = 429;
            $resolvedMessage = 'Request temporarily blocked while abuse signals cool down.';
            $resolvedRetryAfter = $lifecycle['retry_after'];
        } elseif ($lifecycle['action'] === 'hard_block') {
            $resolvedCode = 'hard_blocked';
            $resolvedStatus = 403;
            $resolvedMessage = 'Request temporarily blocked due to repeated abuse signals.';
            $resolvedRetryAfter = $lifecycle['retry_after'];
        }

        $this->recordAbuseSignal(
            request: $request,
            profile: $profile,
            tenantReference: $tenantReference,
            identity: $identity,
            code: $resolvedCode,
            action: $lifecycle['action'],
            correlationId: $correlationId,
            cfRayId: $cfRayId,
            observeMode: $observeMode,
            blocked: ! $observeMode,
            retryAfter: $resolvedRetryAfter,
            stateCount: $lifecycle['count']
        );

        if ($observeMode) {
            $this->observeViolation(
                request: $request,
                profile: $profile,
                code: $resolvedCode,
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                retryAfter: $resolvedRetryAfter,
                lifecycleAction: $lifecycle['action'],
                lifecycleCount: $lifecycle['count']
            );

            return null;
        }

        return $this->buildErrorResponse(
            status: $resolvedStatus,
            code: $resolvedCode,
            message: $resolvedMessage,
            correlationId: $correlationId,
            cfRayId: $cfRayId,
            level: (string) $profile['level'],
            profile: $profile,
            retryAfter: $resolvedRetryAfter
        );
    }

    private function recordAbuseSignal(
        Request $request,
        array $profile,
        ?string $tenantReference,
        string $identity,
        string $code,
        string $action,
        string $correlationId,
        ?string $cfRayId,
        bool $observeMode,
        bool $blocked,
        ?int $retryAfter,
        int $stateCount,
    ): void {
        if (! (bool) config('api_security.abuse_signals.enabled', true)) {
            return;
        }

        try {
            /** @var ApiAbuseSignalRecorder $recorder */
            $recorder = app(ApiAbuseSignalRecorder::class);
            $recorder->recordViolation([
                'code' => $code,
                'action' => $action,
                'level' => (string) ($profile['level'] ?? 'L2'),
                'level_source' => (string) ($profile['level_source'] ?? 'system_default'),
                'tenant_reference' => $tenantReference,
                'identity' => $identity,
                'method' => strtoupper($request->method()),
                'path' => '/'.ltrim($request->path(), '/'),
                'correlation_id' => $correlationId,
                'cf_ray_id' => $cfRayId,
                'observe_mode' => $observeMode,
                'blocked' => $blocked,
                'retry_after' => $retryAfter,
                'state_count' => $stateCount,
                'metadata' => [
                    'security_label' => (string) ($profile['label'] ?? ''),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Failed to persist API abuse signal.', [
                'code' => 'abuse_signal_record_failed',
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }

    private function observeViolation(
        Request $request,
        array $profile,
        string $code,
        string $correlationId,
        ?string $cfRayId,
        ?int $retryAfter = null,
        string $lifecycleAction = 'none',
        int $lifecycleCount = 0,
    ): void {
        Log::warning('API security violation observed (observe_mode=true; request allowed).', [
            'code' => $code,
            'path' => '/'.ltrim($request->path(), '/'),
            'method' => strtoupper($request->method()),
            'level' => (string) ($profile['level'] ?? 'L2'),
            'level_source' => (string) ($profile['level_source'] ?? 'system_default'),
            'correlation_id' => $correlationId,
            'cf_ray_id' => $cfRayId,
            'retry_after' => $retryAfter,
            'lifecycle_action' => $lifecycleAction,
            'lifecycle_count' => $lifecycleCount,
        ]);
    }

    private function withSecurityHeaders(Response $response, string $correlationId, ?string $cfRayId, array $profile): Response
    {
        $response->headers->set('X-Correlation-Id', $correlationId);
        $response->headers->set('X-Api-Security-Level', (string) ($profile['level'] ?? 'L2'));
        $response->headers->set('X-Api-Security-Label', (string) ($profile['label'] ?? 'L2 Balanced'));
        $response->headers->set('X-Api-Security-Level-Source', (string) ($profile['level_source'] ?? 'system_default'));
        $response->headers->set('X-Api-Security-Observe-Mode', $this->isObserveMode() ? 'true' : 'false');

        $edgePolicy = (string) data_get(config('api_security.cloudflare.edge_policy_by_level'), (string) ($profile['level'] ?? 'L2'), '');
        if ($edgePolicy !== '') {
            $response->headers->set('X-Api-Security-Edge-Policy', $edgePolicy);
        }

        if ($cfRayId !== null) {
            $response->headers->set('X-CF-Ray-Id', $cfRayId);
        }

        return $response;
    }

    private function buildErrorResponse(
        int $status,
        string $code,
        string $message,
        string $correlationId,
        ?string $cfRayId,
        string $level,
        array $profile,
        ?int $retryAfter = null,
    ): JsonResponse {
        $payload = [
            'code' => $code,
            'message' => $message,
            'correlation_id' => $correlationId,
        ];

        if ($cfRayId !== null) {
            $payload['cf_ray_id'] = $cfRayId;
        }

        if ($retryAfter !== null) {
            $payload['retry_after'] = $retryAfter;
        }

        $response = response()->json($payload, $status);
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $this->withSecurityHeaders($response, $correlationId, $cfRayId, [
            'level' => $level,
            'label' => (string) data_get(config('api_security.levels.'.$level), 'label', $level),
            'level_source' => (string) ($profile['level_source'] ?? 'system_default'),
        ]);
    }

    private function resolveTenantReference(Request $request): ?string
    {
        try {
            $tenant = Tenant::current();
            if ($tenant !== null) {
                $slug = trim((string) $tenant->getAttribute('slug'));
                if ($slug !== '') {
                    return $slug;
                }

                $id = trim((string) $tenant->getAttribute('_id'));
                if ($id !== '') {
                    return $id;
                }
            }
        } catch (Throwable) {
            // No current tenant in context.
        }

        $tenantSlugParam = trim((string) $request->route('tenant_slug'));
        if ($tenantSlugParam !== '') {
            return $tenantSlugParam;
        }

        $path = ltrim($request->path(), '/');
        if (preg_match('#^admin/api/v1/([^/]+)/#', $path, $matches) === 1) {
            $tenantSlugFromPath = trim((string) ($matches[1] ?? ''));
            if ($tenantSlugFromPath !== '' && ! in_array($tenantSlugFromPath, ['users', 'roles', 'branding', 'security'], true)) {
                return $tenantSlugFromPath;
            }
        }

        $tenantDomainParam = trim((string) $request->route('tenant_domain'));
        if ($tenantDomainParam !== '') {
            return $tenantDomainParam;
        }

        return null;
    }
}
