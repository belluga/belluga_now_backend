<?php

declare(strict_types=1);

namespace Tests\Unit\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileHeroImageResolver;
use Tests\TestCase;

class AccountProfileHeroImageResolverTest extends TestCase
{
    public function test_resolves_cover_before_avatar(): void
    {
        $resolver = new AccountProfileHeroImageResolver;

        $this->assertSame('https://example.org/cover.jpg', $resolver->resolveFromPayload([
            'cover_url' => 'https://example.org/cover.jpg',
            'avatar_url' => 'https://example.org/avatar.jpg',
        ]));
    }

    public function test_resolves_avatar_when_cover_is_blank(): void
    {
        $resolver = new AccountProfileHeroImageResolver;

        $this->assertSame('https://example.org/avatar.jpg', $resolver->resolveFromPayload([
            'cover_url' => ' ',
            'avatar_url' => 'https://example.org/avatar.jpg',
        ]));
    }

    public function test_resolves_type_visual_only_when_explicitly_allowed(): void
    {
        $resolver = new AccountProfileHeroImageResolver;
        $payload = [
            'visual' => [
                'image_url' => 'https://example.org/type-visual.jpg',
            ],
            'type_asset_url' => 'https://example.org/type-asset.jpg',
        ];

        $this->assertNull($resolver->resolveFromPayload($payload));
        $this->assertSame(
            'https://example.org/type-visual.jpg',
            $resolver->resolveFromPayload($payload, allowTypeVisualFallback: true)
        );
    }
}
