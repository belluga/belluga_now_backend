<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
        $profile = $this->resolveProfile($request);
        $observeMode = $this->isObserveMode();

        Log::withContext([
            'correlation_id' => $correlationId,
            'cf_ray_id' => $cfRayId,
            'api_security_level' => (string) $profile['level'],
            'api_security_observe_mode' => $observeMode,
        ]);

        if ($this->shouldEnforceCloudflareOriginLock() && ! $this->isCloudflareRequest($request)) {
            if (! $observeMode) {
                return $this->buildErrorResponse(
                    status: 403,
                    code: 'origin_access_denied',
                    message: 'Direct origin access is not allowed.',
                    correlationId: $correlationId,
                    cfRayId: $cfRayId,
                    level: (string) $profile['level']
                );
            }

            $this->observeViolation($request, $profile, 'origin_access_denied', $correlationId, $cfRayId);
        }

        $rateLimited = $this->enforceRateLimit($request, $profile, $correlationId, $cfRayId, $observeMode);
        if ($rateLimited !== null) {
            return $rateLimited;
        }

        $idempotencyContext = null;
        if ($this->isUnsafeMethod($request) && (bool) ($profile['require_idempotency'] ?? false)) {
            $idempotencyResult = $this->enforceIdempotency($request, $profile, $correlationId, $cfRayId, $observeMode);
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
        /** @var list<string> $headers */
        $headers = (array) config('api_security.cloudflare.presence_headers', ['CF-Ray', 'CF-Connecting-IP']);

        foreach ($headers as $header) {
            if (trim((string) $request->header($header)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{level:string,label:string,requests_per_minute:int,require_idempotency:bool,replay_window_seconds:int}
     */
    private function resolveProfile(Request $request): array
    {
        $path = ltrim($request->path(), '/');
        $method = strtoupper($request->method());

        $defaultLevel = $this->resolveDefaultLevel($path, $method);
        $levels = (array) config('api_security.levels', []);
        $fallback = (array) ($levels[$defaultLevel] ?? []);

        $resolved = [
            'level' => $defaultLevel,
            'label' => (string) ($fallback['label'] ?? $defaultLevel),
            'requests_per_minute' => max(1, (int) ($fallback['requests_per_minute'] ?? 300)),
            'require_idempotency' => (bool) ($fallback['require_idempotency'] ?? false),
            'replay_window_seconds' => max(30, (int) ($fallback['replay_window_seconds'] ?? 600)),
        ];

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

            $overrideLevel = (string) ($override['level'] ?? $resolved['level']);
            $levelConfig = (array) ($levels[$overrideLevel] ?? []);

            $resolved['level'] = $overrideLevel;
            $resolved['label'] = (string) ($levelConfig['label'] ?? $overrideLevel);
            $resolved['requests_per_minute'] = max(1, (int) ($levelConfig['requests_per_minute'] ?? $resolved['requests_per_minute']));
            $resolved['require_idempotency'] = array_key_exists('require_idempotency', $override)
                ? (bool) $override['require_idempotency']
                : (bool) ($levelConfig['require_idempotency'] ?? $resolved['require_idempotency']);
            $resolved['replay_window_seconds'] = max(
                30,
                (int) (
                    $override['replay_window_seconds']
                    ?? $levelConfig['replay_window_seconds']
                    ?? $resolved['replay_window_seconds']
                )
            );

            break;
        }

        return $resolved;
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

    private function isUnsafeMethod(Request $request): bool
    {
        return ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function enforceRateLimit(
        Request $request,
        array $profile,
        string $correlationId,
        ?string $cfRayId,
        bool $observeMode,
    ): ?Response {
        $identity = $this->resolvePrincipalIdentity($request);
        $level = (string) $profile['level'];
        $maxAttempts = (int) $profile['requests_per_minute'];
        $windowSeconds = max(1, (int) config('api_security.rate_limit.window_seconds', 60));
        $prefix = (string) config('api_security.rate_limit.cache_prefix', 'api_security:rate');
        $key = sprintf('%s:%s:%s', $prefix, strtolower($level), $identity);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));
            if ($observeMode) {
                $this->observeViolation($request, $profile, 'rate_limited', $correlationId, $cfRayId, $retryAfter);

                return null;
            }

            return $this->buildErrorResponse(
                status: 429,
                code: 'rate_limited',
                message: 'Too many requests. Retry later.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                level: $level,
                retryAfter: $retryAfter
            );
        }

        RateLimiter::hit($key, $windowSeconds);

        return null;
    }

    /**
     * @return array{cache_key:string,fingerprint:string,replay_window_seconds:int}
     */
    private function enforceIdempotency(
        Request $request,
        array $profile,
        string $correlationId,
        ?string $cfRayId,
        bool $observeMode,
    ): Response|array|null {
        $idempotencyKey = $this->resolveIdempotencyKey($request);
        if ($idempotencyKey === null) {
            if ($observeMode) {
                $this->observeViolation($request, $profile, 'idempotency_missing', $correlationId, $cfRayId);

                return null;
            }

            return $this->buildErrorResponse(
                status: 422,
                code: 'idempotency_missing',
                message: 'Idempotency key is required for this endpoint.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                level: (string) $profile['level']
            );
        }

        if (strlen($idempotencyKey) > 255 || ! preg_match('/^[A-Za-z0-9._:-]{8,255}$/', $idempotencyKey)) {
            if ($observeMode) {
                $this->observeViolation($request, $profile, 'idempotency_malformed', $correlationId, $cfRayId);

                return null;
            }

            return $this->buildErrorResponse(
                status: 422,
                code: 'idempotency_malformed',
                message: 'Idempotency key format is invalid.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                level: (string) $profile['level']
            );
        }

        $identity = $this->resolvePrincipalIdentity($request);
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
                if ($observeMode) {
                    $this->observeViolation($request, $profile, 'idempotency_replayed', $correlationId, $cfRayId);

                    return null;
                }

                return $this->buildErrorResponse(
                    status: 409,
                    code: 'idempotency_replayed',
                    message: 'Request is already being processed.',
                    correlationId: $correlationId,
                    cfRayId: $cfRayId,
                    level: (string) $profile['level']
                );
            }

            $existingFingerprint = (string) ($existing['fingerprint'] ?? '');
            if ($existingFingerprint !== $fingerprint) {
                if ($observeMode) {
                    $this->observeViolation($request, $profile, 'idempotency_replayed', $correlationId, $cfRayId);

                    return null;
                }

                return $this->buildErrorResponse(
                    status: 409,
                    code: 'idempotency_replayed',
                    message: 'Idempotency key was already used with a different payload.',
                    correlationId: $correlationId,
                    cfRayId: $cfRayId,
                    level: (string) $profile['level']
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

            if ($observeMode) {
                $this->observeViolation($request, $profile, 'idempotency_replayed', $correlationId, $cfRayId);

                return null;
            }

            return $this->buildErrorResponse(
                status: 409,
                code: 'idempotency_replayed',
                message: 'Request is already being processed.',
                correlationId: $correlationId,
                cfRayId: $cfRayId,
                level: (string) $profile['level']
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

        $ip = trim((string) $request->ip());

        return $ip !== '' ? 'ip:'.$ip : 'anon';
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

    private function observeViolation(
        Request $request,
        array $profile,
        string $code,
        string $correlationId,
        ?string $cfRayId,
        ?int $retryAfter = null,
    ): void {
        Log::warning('API security violation observed (observe_mode=true; request allowed).', [
            'code' => $code,
            'path' => '/'.ltrim($request->path(), '/'),
            'method' => strtoupper($request->method()),
            'level' => (string) ($profile['level'] ?? 'L2'),
            'correlation_id' => $correlationId,
            'cf_ray_id' => $cfRayId,
            'retry_after' => $retryAfter,
        ]);
    }

    private function withSecurityHeaders(Response $response, string $correlationId, ?string $cfRayId, array $profile): Response
    {
        $response->headers->set('X-Correlation-Id', $correlationId);
        $response->headers->set('X-Api-Security-Level', (string) ($profile['level'] ?? 'L2'));
        $response->headers->set('X-Api-Security-Label', (string) ($profile['label'] ?? 'L2 Balanced'));
        $response->headers->set('X-Api-Security-Observe-Mode', $this->isObserveMode() ? 'true' : 'false');

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
        ]);
    }
}
