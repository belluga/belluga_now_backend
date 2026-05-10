<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Exceptions\MultiplePushCredentialsException;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FcmHttpV1Client implements FcmClientContract
{
    private const BATCH_ENDPOINT = 'https://fcm.googleapis.com/batch';

    public function __construct(
        private readonly PushCredentialService $credentialService
    ) {}

    /**
     * @param  array<int, string>  $tokens
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function send(PushMessage $message, array $tokens, string $messageInstanceId, Carbon $expiresAt, int $ttlMinutes): array
    {
        try {
            $credentials = $this->credentialService->current();
        } catch (MultiplePushCredentialsException $exception) {
            return ['accepted_count' => 0, 'responses' => []];
        }
        if (! $credentials) {
            return ['accepted_count' => 0, 'responses' => []];
        }

        $accessToken = $this->accessToken(
            projectId: (string) $credentials->project_id,
            clientEmail: (string) $credentials->client_email,
            privateKey: (string) $credentials->private_key
        );

        if ($accessToken === null) {
            return ['accepted_count' => 0, 'responses' => []];
        }

        $endpoint = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $credentials->project_id);
        $basePayload = $this->buildPayload($message, $messageInstanceId, $expiresAt);

        $responses = [];
        $accepted = 0;
        $batchSize = (int) config('belluga_push_handler.fcm.max_batch_size', 500);
        if ($batchSize <= 0) {
            $batchSize = 500;
        }

        foreach (array_chunk($tokens, $batchSize) as $chunk) {
            $batchResponses = $this->sendBatchChunk($accessToken, $endpoint, $basePayload, $chunk);

            foreach ($batchResponses as $entry) {
                if (($entry['status'] ?? null) === 'accepted') {
                    $accepted++;
                }

                $responses[] = $entry;
            }
        }

        return [
            'accepted_count' => $accepted,
            'responses' => $responses,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(PushMessage $message, string $messageInstanceId, Carbon $expiresAt): array
    {
        $fcmOptions = $message->fcm_options ?? [];

        $notification = $fcmOptions['notification'] ?? [
            'title' => $message->title_template,
            'body' => $message->body_template,
        ];

        $data = $fcmOptions['data'] ?? [];
        if (! is_array($data)) {
            $data = [];
        }
        $data['push_message_id'] = (string) $message->_id;
        $data['message_instance_id'] = $messageInstanceId;

        $payload = [
            'notification' => $notification,
            'data' => $data,
        ];

        foreach (['android', 'apns', 'webpush'] as $platform) {
            if (isset($fcmOptions[$platform]) && is_array($fcmOptions[$platform])) {
                $payload[$platform] = $fcmOptions[$platform];
            }
        }

        $ttlSeconds = max(0, (int) Carbon::now()->diffInSeconds($expiresAt, false));
        $payload['android']['ttl'] = $ttlSeconds.'s';
        $payload['webpush']['headers']['TTL'] = (string) $ttlSeconds;
        $payload['apns']['headers']['apns-expiration'] = (string) $expiresAt->getTimestamp();

        return $payload;
    }

    private function accessToken(string $projectId, string $clientEmail, string $privateKey): ?string
    {
        $cacheKey = 'fcm_access_token:'.$projectId.':'.sha1($clientEmail);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($clientEmail, $privateKey): ?string {
            $jwt = $this->buildJwt($clientEmail, $privateKey);
            if (! $jwt) {
                return null;
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                return null;
            }

            return (string) ($response->json('access_token') ?? '');
        });
    }

    private function buildJwt(string $clientEmail, string $privateKey): ?string
    {
        $now = time();
        $payload = [
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode($payload)),
        ];

        $signingInput = implode('.', $segments);
        $signature = '';

        $privateKeyResource = openssl_pkey_get_private($privateKey);
        if ($privateKeyResource === false) {
            return null;
        }

        $signed = openssl_sign($signingInput, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKeyResource);

        if (! $signed) {
            return null;
        }

        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $basePayload
     * @return array<int, array<string, mixed>>
     */
    private function sendBatchChunk(string $accessToken, string $endpoint, array $basePayload, array $tokens): array
    {
        $boundary = 'batch_'.bin2hex(random_bytes(12));
        $requestPath = $this->requestPath($endpoint);
        $body = $this->buildBatchBody($requestPath, $basePayload, $tokens, $boundary);

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => "multipart/mixed; boundary={$boundary}",
                    'Accept' => 'multipart/mixed',
                ])
                ->send('POST', self::BATCH_ENDPOINT, [
                    'body' => $body,
                ]);
        } catch (ConnectionException $exception) {
            return array_map(static fn (string $token): array => [
                'token' => $token,
                'status' => 'failed',
                'error_code' => 'connection_error',
                'error_message' => $exception->getMessage(),
            ], $tokens);
        }

        if (! $response->successful()) {
            return array_map(static fn (string $token): array => [
                'token' => $token,
                'status' => 'failed',
                'error_code' => (string) $response->status(),
                'error_message' => $response->body(),
            ], $tokens);
        }

        return $this->parseBatchResponse($response, $tokens);
    }

    private function requestPath(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? null;

        return $query ? $path.'?'.$query : $path;
    }

    /**
     * @param  array<string, mixed>  $basePayload
     * @param  array<int, string>  $tokens
     */
    private function buildBatchBody(string $requestPath, array $basePayload, array $tokens, string $boundary): string
    {
        $parts = [];

        foreach ($tokens as $index => $token) {
            $payload = $basePayload;
            $payload['token'] = $token;

            $parts[] = implode("\r\n", [
                "--{$boundary}",
                'Content-Type: application/http',
                "Content-ID: <item-{$index}>",
                '',
                "POST {$requestPath} HTTP/1.1",
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json',
                '',
                json_encode(['message' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                '',
            ]);
        }

        $parts[] = "--{$boundary}--";

        return implode("\r\n", $parts)."\r\n";
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, array<string, mixed>>
     */
    private function parseBatchResponse(Response $response, array $tokens): array
    {
        $contentType = (string) ($response->header('Content-Type')[0] ?? '');
        $boundary = $this->extractBoundary($contentType);

        if ($boundary === null) {
            return array_map(static fn (string $token): array => [
                'token' => $token,
                'status' => 'failed',
                'error_code' => 'invalid_batch_response',
                'error_message' => 'FCM batch response did not expose a multipart boundary.',
            ], $tokens);
        }

        $resolved = [];
        $segments = explode('--'.$boundary, $response->body());
        foreach ($segments as $segment) {
            $segment = ltrim($segment, "\r\n");
            $segment = rtrim($segment, "\r\n");
            if ($segment === '' || $segment === '--') {
                continue;
            }

            [$partHeadersRaw, $partBodyRaw] = array_pad(preg_split("/\r\n\r\n/", $segment, 2), 2, '');
            $contentId = $this->extractContentId($partHeadersRaw);
            $tokenIndex = $this->extractTokenIndex($contentId);
            if ($tokenIndex === null || ! array_key_exists($tokenIndex, $tokens)) {
                continue;
            }

            [$statusLine, $nestedBody] = $this->extractNestedHttpResponse($partBodyRaw);
            $statusCode = $this->extractStatusCode($statusLine);
            $decoded = json_decode($nestedBody, true);
            $token = $tokens[$tokenIndex];

            if ($statusCode >= 200 && $statusCode < 300) {
                $resolved[$tokenIndex] = [
                    'token' => $token,
                    'status' => 'accepted',
                    'provider_message_id' => (string) (($decoded['name'] ?? '') ?: ''),
                ];

                continue;
            }

            $resolved[$tokenIndex] = [
                'token' => $token,
                'status' => 'failed',
                'error_code' => (string) (($decoded['error']['status'] ?? '') ?: $statusCode),
                'error_message' => (string) (($decoded['error']['message'] ?? '') ?: $nestedBody),
            ];
        }

        foreach ($tokens as $index => $token) {
            if (isset($resolved[$index])) {
                continue;
            }

            $resolved[$index] = [
                'token' => $token,
                'status' => 'failed',
                'error_code' => 'missing_batch_part',
                'error_message' => 'No batch response part was returned for token.',
            ];
        }

        ksort($resolved);

        return array_values($resolved);
    }

    private function extractBoundary(string $contentType): ?string
    {
        if (! preg_match('/boundary="?([^";]+)"?/i', $contentType, $matches)) {
            return null;
        }

        return trim((string) ($matches[1] ?? ''));
    }

    private function extractContentId(string $headers): ?string
    {
        if (! preg_match('/^Content-ID:\s*(.+)$/im', $headers, $matches)) {
            return null;
        }

        return trim((string) ($matches[1] ?? ''));
    }

    private function extractTokenIndex(?string $contentId): ?int
    {
        if (! is_string($contentId) || $contentId === '') {
            return null;
        }

        if (! preg_match('/item-(\d+)/', $contentId, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function extractNestedHttpResponse(string $partBody): array
    {
        $partBody = ltrim($partBody, "\r\n");
        [$statusAndHeaders, $nestedBody] = array_pad(preg_split("/\r\n\r\n/", $partBody, 2), 2, '');
        [$statusLine] = preg_split("/\r\n/", $statusAndHeaders, 2);

        return [trim((string) $statusLine), trim($nestedBody)];
    }

    private function extractStatusCode(string $statusLine): int
    {
        if (! preg_match('/\s(\d{3})\s/', $statusLine.' ', $matches)) {
            return 0;
        }

        return (int) $matches[1];
    }
}
