<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FcmHttpV1Client implements FcmClientContract
{
    public function __construct(
        private readonly PushCredentialService $credentialService
    ) {
    }

    /**
     * @param array<int, string> $tokens
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function send(PushMessage $message, array $tokens): array
    {
        $credentials = $this->credentialService->current();
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
        $basePayload = $this->buildPayload($message);

        $responses = [];
        $accepted = 0;
        foreach (array_chunk($tokens, 500) as $chunk) {
            $batchResponses = Http::pool(function (Pool $pool) use ($chunk, $accessToken, $endpoint, $basePayload) {
                $requests = [];
                foreach ($chunk as $token) {
                    $payload = $basePayload;
                    $payload['token'] = $token;
                    $requests[] = $pool
                        ->withToken($accessToken)
                        ->post($endpoint, ['message' => $payload]);
                }

                return $requests;
            });

            foreach ($batchResponses as $index => $response) {
                $token = $chunk[$index] ?? null;
                if (! is_string($token) || $token === '') {
                    continue;
                }

                if ($response instanceof ConnectionException) {
                    $responses[] = [
                        'token' => $token,
                        'status' => 'failed',
                        'error_code' => 'connection_error',
                        'error_message' => $response->getMessage(),
                    ];
                    continue;
                }

                if ($response->successful()) {
                    $accepted++;
                    $responses[] = [
                        'token' => $token,
                        'status' => 'accepted',
                        'provider_message_id' => (string) ($response->json('name') ?? ''),
                    ];
                    continue;
                }

                $responses[] = [
                    'token' => $token,
                    'status' => 'failed',
                    'error_code' => (string) ($response->json('error.status') ?? ''),
                    'error_message' => (string) ($response->json('error.message') ?? $response->body()),
                ];
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
    private function buildPayload(PushMessage $message): array
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

        $payload = [
            'notification' => $notification,
            'data' => $data,
        ];

        foreach (['android', 'apns', 'webpush'] as $platform) {
            if (isset($fcmOptions[$platform]) && is_array($fcmOptions[$platform])) {
                $payload[$platform] = $fcmOptions[$platform];
            }
        }

        return $payload;
    }

    private function accessToken(string $projectId, string $clientEmail, string $privateKey): ?string
    {
        $cacheKey = 'fcm_access_token:' . $projectId . ':' . sha1($clientEmail);

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
}
