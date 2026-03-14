<?php

declare(strict_types=1);

namespace Belluga\Events\Support;

final class EventAsyncQueueMetricsSnapshot
{
    public const string STATUS_AVAILABLE = 'available';

    public const string STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @param  array<int, int>  $pendingAgesInSeconds
     */
    private function __construct(
        private readonly string $status,
        private readonly array $pendingAgesInSeconds,
        private readonly ?string $reason = null
    ) {}

    /**
     * @param  array<int, int>  $pendingAgesInSeconds
     */
    public static function available(array $pendingAgesInSeconds): self
    {
        return new self(self::STATUS_AVAILABLE, $pendingAgesInSeconds);
    }

    public static function unavailable(string $reason): self
    {
        return new self(self::STATUS_UNAVAILABLE, [], $reason);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function isUnavailable(): bool
    {
        return $this->status === self::STATUS_UNAVAILABLE;
    }

    public function isEmpty(): bool
    {
        return $this->isAvailable() && $this->pendingAgesInSeconds === [];
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<int, int>
     */
    public function pendingAgesInSeconds(): array
    {
        return $this->pendingAgesInSeconds;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
