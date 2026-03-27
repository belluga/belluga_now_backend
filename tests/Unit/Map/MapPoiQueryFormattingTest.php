<?php

declare(strict_types=1);

namespace Tests\Unit\Map;

use Belluga\MapPois\Application\Concerns\MapPoiQueryFormatting;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\TestCase;

class MapPoiQueryFormattingTest extends TestCase
{
    public function test_format_visual_accepts_bson_document_icon_payload(): void
    {
        $harness = new class
        {
            use MapPoiQueryFormatting {
                formatVisual as public;
            }
        };

        $formatted = $harness->formatVisual(new BSONDocument([
            'mode' => 'icon',
            'icon' => 'restaurant',
            'color' => '#EB2528',
            'icon_color' => '#101010',
            'source' => 'type_definition',
        ]));

        $this->assertIsArray($formatted);
        $this->assertSame('icon', $formatted['mode'] ?? null);
        $this->assertSame('restaurant', $formatted['icon'] ?? null);
        $this->assertSame('#EB2528', $formatted['color'] ?? null);
        $this->assertSame('#101010', $formatted['icon_color'] ?? null);
        $this->assertSame('type_definition', $formatted['source'] ?? null);
    }
}
