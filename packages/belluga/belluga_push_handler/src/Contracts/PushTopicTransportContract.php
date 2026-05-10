<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

interface PushTopicTransportContract
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function subscribe(string $topic, array $tokens): void;

    /**
     * @param  array<int, string>  $tokens
     */
    public function unsubscribe(string $topic, array $tokens): void;

    /**
     * @param  array<int, string>  $tokens
     */
    public function unsubscribeFromAll(array $tokens): void;
}
