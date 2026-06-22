<?php

declare(strict_types=1);

namespace Belluga\Media\Application;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Laravel\Facades\Image;
use RuntimeException;

final class CanonicalImageProcessor
{
    public const MASTER_LONGEST_EDGE = 2048;

    /**
     * @var array<string, int>
     */
    public const DEFAULT_PUBLIC_VARIANTS = [
        'thumb' => 320,
        'card' => 960,
        'modal' => 1600,
    ];

    /**
     * @param  array<string, int>  $variantLongestEdges
     * @return array{
     *     extension:string,
     *     mime_type:string,
     *     master:string,
     *     variants:array<string, string>
     * }
     */
    public function processUpload(
        UploadedFile $file,
        int $masterLongestEdge = self::MASTER_LONGEST_EDGE,
        array $variantLongestEdges = self::DEFAULT_PUBLIC_VARIANTS,
    ): array {
        $sourcePath = $file->getRealPath();
        if (! is_string($sourcePath) || trim($sourcePath) === '') {
            throw new RuntimeException('Uploaded image path is not available.');
        }

        $extension = $this->resolveTargetExtension($file);
        $mimeType = $this->resolveMimeType($extension);

        $masterImage = Image::read($sourcePath)->orient();
        $masterImage = $this->scaleToLongestEdge($masterImage, $masterLongestEdge);

        $processedVariants = [];
        foreach ($variantLongestEdges as $variant => $longestEdge) {
            $variantImage = Image::read($sourcePath)->orient();
            $variantImage = $this->scaleToLongestEdge(
                $variantImage,
                min($masterLongestEdge, max(1, (int) $longestEdge))
            );
            $processedVariants[$variant] = $this->encodeImage($variantImage, $extension);
        }

        return [
            'extension' => $extension,
            'mime_type' => $mimeType,
            'master' => $this->encodeImage($masterImage, $extension),
            'variants' => $processedVariants,
        ];
    }

    private function scaleToLongestEdge(mixed $image, int $longestEdge): mixed
    {
        $boundedEdge = max(1, $longestEdge);
        if ($image->width() <= $boundedEdge && $image->height() <= $boundedEdge) {
            return $image;
        }

        return $image->scaleDown($boundedEdge, $boundedEdge);
    }

    private function encodeImage(mixed $image, string $extension): string
    {
        return match ($extension) {
            'png' => $image->toPng()->toString(),
            'webp' => $image->toWebp(88, true)->toString(),
            default => $image->toJpeg(88, false, true)->toString(),
        };
    }

    private function resolveTargetExtension(UploadedFile $file): string
    {
        $mimeType = strtolower(trim((string) $file->getMimeType()));
        if ($mimeType === 'image/png') {
            return 'png';
        }
        if ($mimeType === 'image/webp') {
            return 'webp';
        }

        return 'jpg';
    }

    private function resolveMimeType(string $extension): string
    {
        return match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
