<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Contracts\FcmTopicSenderContract;
use Belluga\PushHandler\Exceptions\MultiplePushCredentialsException;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FcmHttpV1Client implements FcmClientContract, FcmTopicSenderContract
{
    public function __construct(
        private readonly PushCredentialService $credentialService
    ) {}

    /**
     * @param  array<int, string>  $tokens
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function send(PushMessage $message, array $tokens, string $messageInstanceId, Carbon $expiresAt, int $ttlMinutes): array
    {
        [$accessToken, $endpoint] = $this->bootstrapTransport();
        if ($accessToken === null || $endpoint === null) {
            return ['accepted_count' => 0, 'responses' => []];
        }

        $basePayload = $this->buildPayload($message, $messageInstanceId, $expiresAt);
        $responses = [];
        $accepted = 0;

        foreach ($tokens as $token) {
            $entry = $this->sendTarget($accessToken, $endpoint, $basePayload, 'token', $token);
            if (($entry['status'] ?? null) === 'accepted') {
                $accepted++;
            }

            $responses[] = $entry;
        }

        return [
            'accepted_count' => $accepted,
            'responses' => $responses,
        ];
    }

    /**
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function sendTopic(PushMessage $message, string $topic, string $messageInstanceId, Carbon $expiresAt, int $ttlMinutes): array
    {
        [$accessToken, $endpoint] = $this->bootstrapTransport();
        if ($accessToken === null || $endpoint === null) {
            return ['accepted_count' => 0, 'responses' => []];
        }

        $topic = trim($topic);
        if ($topic === '') {
            return ['accepted_count' => 0, 'responses' => []];
        }

        $basePayload = $this->buildPayload($message, $messageInstanceId, $expiresAt);
        $entry = $this->sendTarget($accessToken, $endpoint, $basePayload, 'topic', $topic);

        return [
            'accepted_count' => ($entry['status'] ?? null) === 'accepted' ? 1 : 0,
            'responses' => [$entry],
        ];
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function bootstrapTransport(): array
    {
        try {
            $credentials = $this->credentialService->current();
        } catch (MultiplePushCredentialsException $exception) {
            return [null, null];
        }
        if (! $credentials) {
            return [null, null];
        }

        $accessToken = $this->accessToken(
            projectId: (string) $credentials->project_id,
            clientEmail: (string) $credentials->client_email,
            privateKey: (string) $credentials->private_key
        );

        if ($accessToken === null) {
            return [null, null];
        }

        return [
            $accessToken,
            sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $credentials->project_id),
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
     * @param  array<string, mixed>  $basePayload
     * @return array<string, mixed>
     */
    private function sendTarget(
        string $accessToken,
        string $endpoint,
        array $basePayload,
        string $targetField,
        string $targetValue
    ): array {
        $payload = $basePayload;
        $payload[$targetField] = $targetValue;

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, [
                    'message' => $payload,
                ]);
        } catch (ConnectionException $exception) {
            return [
                $targetField => $targetValue,
                'status' => 'failed',
                'error_code' => 'connection_error',
                'error_message' => $exception->getMessage(),
            ];
        }

        if ($response->successful()) {
            return [
                $targetField => $targetValue,
                'status' => 'accepted',
                'provider_message_id' => (string) (($response->json('name') ?? '') ?: ''),
            ];
        }

        return [
            $targetField => $targetValue,
            'status' => 'failed',
            'error_code' => (string) (($response->json('error.status') ?? '') ?: $response->status()),
            'error_message' => (string) (($response->json('error.message') ?? '') ?: $response->body()),
        ];
    }
}
