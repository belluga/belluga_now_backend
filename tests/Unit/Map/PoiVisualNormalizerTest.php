<?php

declare(strict_types=1);

namespace Tests\Unit\Map;

use App\Application\Shared\MapPois\PoiVisualNormalizer;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\TestCase;

class PoiVisualNormalizerTest extends TestCase
{
    public function test_normalize_accepts_bson_document_icon_payload(): void
    {
        $normalizer = new PoiVisualNormalizer;

        $normalized = $normalizer->normalize(new BSONDocument([
            'mode' => 'icon',
            'icon' => 'restaurant',
            'color' => '#eb2528',
            'icon_color' => '#0f0f0f',
        ]));

        $this->assertIsArray($normalized);
        $this->assertSame('icon', $normalized['mode'] ?? null);
        $this->assertSame('restaurant', $normalized['icon'] ?? null);
        $this->assertSame('#EB2528', $normalized['color'] ?? null);
        $this->assertSame('#0F0F0F', $normalized['icon_color'] ?? null);
    }

    public function test_normalize_rejects_invalid_icon_color(): void
    {
        $normalizer = new PoiVisualNormalizer;

        $normalized = $normalizer->normalize([
            'mode' => 'icon',
            'icon' => 'restaurant',
            'color' => '#EB2528',
            'icon_color' => 'white',
        ]);

        $this->assertNull($normalized);
    }
}
