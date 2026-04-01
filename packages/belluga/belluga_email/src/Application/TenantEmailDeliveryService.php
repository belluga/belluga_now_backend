<?php

declare(strict_types=1);

namespace Belluga\Email\Application;

use Belluga\Email\Contracts\EmailSettingsSourceContract;
use Belluga\Email\Contracts\EmailTenantContextContract;
use Belluga\Email\Exceptions\EmailDeliveryException;
use Belluga\Email\Exceptions\EmailIntegrationPendingException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TenantEmailDeliveryService
{
    public function __construct(
        private readonly EmailSettingsSourceContract $settings,
        private readonly EmailTenantContextContract $tenantContext,
    ) {}

    /**
     * @param  array{email:string, whatsapp:string, os:string, app_name?:string}  $payload
     * @return array{provider:string, message_id:?string}
     */
    public function sendTesterWaitlistLead(array $payload): array
    {
        $config = $this->settings->currentConfig();
        if (! $this->settings->isConfigured($config)) {
            throw new EmailIntegrationPendingException(
                $this->settings->missingRequiredFields($config),
            );
        }

        $tenantName = trim((string) ($this->tenantContext->currentTenantDisplayName() ?? ''));
        $appName = trim((string) ($payload['app_name'] ?? ''));
        $displayName = $tenantName !== '' ? $tenantName : ($appName !== '' ? $appName : 'Belluga');
        $platform = trim((string) ($payload['os'] ?? ''));

        $response = Http::timeout((int) config('belluga_email.resend.timeout_seconds', 10))
            ->acceptJson()
            ->asJson()
            ->withToken((string) $config['token'])
            ->post($this->endpoint(), $this->buildResendPayload(
                $config,
                sprintf('🚀 Novo Testador VIP: %s (%s)', $displayName, $platform),
                (string) $payload['email'],
                (string) $payload['whatsapp'],
                $platform,
                $displayName,
            ));

        if (! $response->successful()) {
            throw new EmailDeliveryException($this->resolveErrorMessage($response));
        }

        $messageId = data_get($response->json(), 'id');
        if ($messageId !== null && ! is_string($messageId)) {
            $messageId = (string) $messageId;
        }

        return [
            'provider' => 'resend',
            'message_id' => $messageId !== '' ? $messageId : null,
        ];
    }

    private function endpoint(): string
    {
        return rtrim((string) config('belluga_email.resend.base_url', 'https://api.resend.com'), '/').'/emails';
    }

    /**
     * @param  array{
     *   token:?string,
     *   from:?string,
     *   to:array<int, string>,
     *   cc:array<int, string>,
     *   bcc:array<int, string>,
     *   reply_to:array<int, string>
     * }  $config
     * @return array<string, mixed>
     */
    private function buildResendPayload(
        array $config,
        string $subject,
        string $email,
        string $whatsapp,
        string $os,
        string $appName,
    ): array {
        $html = implode('', [
            '<h1>Novo cadastro de testador</h1>',
            '<p><strong>Aplicativo:</strong> '.e($appName).'</p>',
            '<p><strong>E-mail:</strong> '.e($email).'</p>',
            '<p><strong>WhatsApp:</strong> '.e($whatsapp).'</p>',
            '<p><strong>Celular:</strong> '.e($os).'</p>',
            '<p><strong>Data:</strong> '.e(now()->toIso8601String()).'</p>',
        ]);

        $text = implode("\n", [
            'Novo cadastro de testador',
            "Aplicativo: {$appName}",
            "E-mail: {$email}",
            "WhatsApp: {$whatsapp}",
            "Celular: {$os}",
            'Data: '.now()->toIso8601String(),
        ]);

        $payload = [
            'from' => $config['from'],
            'to' => $config['to'],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];

        if ($config['cc'] !== []) {
            $payload['cc'] = $config['cc'];
        }
        if ($config['bcc'] !== []) {
            $payload['bcc'] = $config['bcc'];
        }
        if ($config['reply_to'] !== []) {
            $payload['reply_to'] = $config['reply_to'];
        }

        return $payload;
    }

    private function resolveErrorMessage(Response $response): string
    {
        $message = data_get($response->json(), 'message');
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        return 'Nao foi possivel enviar seu contato agora. Tente novamente em instantes.';
    }
}
