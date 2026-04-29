<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

use App\Application\Auth\PhoneOtpWebhookDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

class DeliverPhoneOtpWebhookJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        private readonly string $webhookUrl,
        private readonly string $channel,
        private readonly string $phone,
        private readonly string $code,
        private readonly string $challengeId,
        private readonly string $expiresAt,
    ) {
        $this->onQueue('otp');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function webhookUrl(): string
    {
        return $this->webhookUrl;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function handle(PhoneOtpWebhookDeliveryService $deliveryService): void
    {
        $deliveryService->deliver($this->webhookUrl, [
            'type' => 'phone_otp.challenge',
            'channel' => $this->channel,
            'phone' => $this->phone,
            'code' => $this->code,
            'challenge_id' => $this->challengeId,
            'expires_at' => $this->expiresAt,
        ]);
    }
}
