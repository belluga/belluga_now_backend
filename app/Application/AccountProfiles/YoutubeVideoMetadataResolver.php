<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Illuminate\Support\Facades\Http;
use Throwable;

final class YoutubeVideoMetadataResolver
{
    public const float DEFAULT_ASPECT_RATIO = 1.777778;

    public function playerAspectRatio(string $videoId): float
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(1)
                ->timeout(2)
                ->retry(2, 100, throw: false)
                ->get('https://www.youtube.com/oembed', [
                    'url' => "https://www.youtube.com/shorts/{$videoId}",
                    'format' => 'json',
                ]);
            if ($response->failed()) {
                return self::DEFAULT_ASPECT_RATIO;
            }

            $width = (int) $response->json('width', 0);
            $height = (int) $response->json('height', 0);
            if ($width <= 0 || $height <= 0) {
                return self::DEFAULT_ASPECT_RATIO;
            }

            return round($width / $height, 6);
        } catch (Throwable) {
            return self::DEFAULT_ASPECT_RATIO;
        }
    }
}
