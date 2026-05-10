<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Belluga\PushHandler\Contracts\PushTopicTransportContract;

final class FakePushTopicTransport implements PushTopicTransportContract
{
    /**
     * @var array<int, array{topic:string,tokens:array<int,string>}>
     */
    public array $subscriptions = [];

    /**
     * @var array<int, array{topic:string,tokens:array<int,string>}>
     */
    public array $unsubscriptions = [];

    /**
     * @var array<int, array<int,string>>
     */
    public array $unsubscribeAll = [];

    public function subscribe(string $topic, array $tokens): void
    {
        $this->subscriptions[] = [
            'topic' => $topic,
            'tokens' => array_values($tokens),
        ];
    }

    public function unsubscribe(string $topic, array $tokens): void
    {
        $this->unsubscriptions[] = [
            'topic' => $topic,
            'tokens' => array_values($tokens),
        ];
    }

    public function unsubscribeFromAll(array $tokens): void
    {
        $this->unsubscribeAll[] = array_values($tokens);
    }
}
