<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use App\Support\Validation\InputConstraints;
use Illuminate\Validation\ValidationException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

final class AccountProfileExternalLinkRegistry
{
    public function __construct(
        private readonly ?AccountProfileExternalLinkLimitResolver $limitResolver = null,
    ) {}

    /** @var array<string, array{label:string,hosts:list<string>}> */
    private const DEFINITIONS = [
        'instagram' => ['label' => 'Instagram', 'hosts' => ['instagram.com', 'www.instagram.com']],
        'facebook' => ['label' => 'Facebook', 'hosts' => ['facebook.com', 'www.facebook.com', 'm.facebook.com']],
        'youtube' => ['label' => 'YouTube', 'hosts' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be']],
        'tiktok' => ['label' => 'TikTok', 'hosts' => ['tiktok.com', 'www.tiktok.com']],
        'spotify' => ['label' => 'Spotify', 'hosts' => ['open.spotify.com', 'spotify.link']],
        'website' => ['label' => 'Website', 'hosts' => []],
    ];

    public function currentLimit(?AccountProfile $profile = null): int
    {
        return ($this->limitResolver ?? new AccountProfileExternalLinkLimitResolver)->resolve($profile);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function semanticLabel(string $type): ?string
    {
        return self::DEFINITIONS[$type]['label'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type:string,url:string,label?:string}
     */
    public function normalizeForWrite(array $payload): array
    {
        $type = trim((string) ($payload['type'] ?? ''));
        if (! isset(self::DEFINITIONS[$type])) {
            $this->fail('type', 'The selected external link type is not supported.');
        }

        $url = $this->normalizeUrl($type, $payload['url'] ?? null);
        if ($type !== 'website') {
            if (array_key_exists('label', $payload)) {
                $this->fail('label', 'A label is only allowed for website links.');
            }

            return ['type' => $type, 'url' => $url];
        }

        $label = trim((string) ($payload['label'] ?? ''));
        if ($label === '') {
            $this->fail('label', 'A website label is required.');
        }
        if (mb_strlen($label) > InputConstraints::NAME_MAX) {
            $this->fail('label', 'The website label is too long.');
        }

        return ['type' => $type, 'url' => $url, 'label' => $label];
    }

    /**
     * @return list<array{id:string,type:string,url:string,label?:string}>
     */
    public function normalizeStored(mixed $value, ?int $limit = null): array
    {
        $resolvedLimit = $limit ?? $this->currentLimit();
        if ($this->storedValueExceedsLimit($value, $resolvedLimit)) {
            return [];
        }

        $items = $this->arrayFrom($value);
        $typeCounts = [];
        $idCounts = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            $type = trim((string) ($item['type'] ?? ''));
            if (isset(self::DEFINITIONS[$type])) {
                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            }
            if ($id !== '') {
                $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
            }
        }

        $resolvedByType = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            $type = trim((string) ($item['type'] ?? ''));
            if ($id === ''
                || ! isset(self::DEFINITIONS[$type])
                || ($typeCounts[$type] ?? 0) !== 1
                || ($idCounts[$id] ?? 0) !== 1) {
                continue;
            }

            try {
                $normalized = $this->normalizeForWrite($item);
            } catch (ValidationException) {
                continue;
            }

            $resolvedByType[$type] = ['id' => $id, ...$normalized];
        }

        $resolved = [];
        foreach ($this->types() as $type) {
            if (isset($resolvedByType[$type])) {
                $resolved[] = $resolvedByType[$type];
            }
            if (count($resolved) >= $resolvedLimit) {
                break;
            }
        }

        return $resolved;
    }

    private function storedValueExceedsLimit(mixed $value, int $limit): bool
    {
        if (is_array($value)) {
            return count($value) > $limit;
        }

        if ($value instanceof \Countable) {
            return count($value) > $limit;
        }

        return false;
    }

    private function normalizeUrl(string $type, mixed $value): string
    {
        if (! is_string($value)) {
            $this->fail('url', 'An external link URL is required.');
        }

        $url = trim($value);
        if ($url === '' || strlen($url) > InputConstraints::ACCOUNT_PROFILE_EXTERNAL_LINK_URL_MAX) {
            $this->fail('url', 'The external link URL must not exceed 2048 characters.');
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            $this->fail('url', 'The external link must be an absolute HTTPS URL without credentials.');
        }

        if ($type !== 'website') {
            $host = strtolower((string) $parts['host']);
            if (! in_array($host, self::DEFINITIONS[$type]['hosts'], true)) {
                $this->fail('url', 'The external link host does not match the selected type.');
            }

            if (trim((string) ($parts['path'] ?? ''), '/') === '') {
                $this->fail('url', 'The external link must address a provider destination.');
            }
        }

        return $url;
    }

    /** @return array<int, mixed> */
    private function arrayFrom(mixed $value): array
    {
        if ($value instanceof BSONArray || $value instanceof BSONDocument) {
            return $value->getArrayCopy();
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return is_array($value) ? $value : [];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
