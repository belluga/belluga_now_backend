<?php

declare(strict_types=1);

namespace App\Application\Shared\MapPois;

final class PoiVisualNormalizer
{
    /**
     * @return array<string, string>|null
     */
    public function normalize(mixed $raw): ?array
    {
        $raw = $this->normalizeDocument($raw);
        if (! is_array($raw)) {
            return null;
        }

        $mode = strtolower(trim((string) ($raw['mode'] ?? '')));
        if ($mode === 'icon') {
            $icon = trim((string) ($raw['icon'] ?? ''));
            $color = $this->normalizeHexColor($raw['color'] ?? null);
            $iconColor = $this->normalizeHexColor($raw['icon_color'] ?? '#FFFFFF');
            if ($icon === '' || $color === null || $iconColor === null) {
                return null;
            }

            return [
                'mode' => 'icon',
                'icon' => $icon,
                'color' => $color,
                'icon_color' => $iconColor,
            ];
        }

        if ($mode === 'image') {
            $imageSource = strtolower(trim((string) ($raw['image_source'] ?? '')));
            if (! in_array($imageSource, ['avatar', 'cover', 'type_asset'], true)) {
                return null;
            }

            $visual = [
                'mode' => 'image',
                'image_source' => $imageSource,
            ];

            $color = $this->normalizeHexColor($raw['color'] ?? null);
            if ($color !== null) {
                $visual['color'] = $color;
            }

            return $visual;
        }

        return null;
    }

    private function normalizeHexColor(mixed $raw): ?string
    {
        $value = strtoupper(trim((string) $raw));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^#[0-9A-F]{6}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeDocument(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $copy = $value->getArrayCopy();

            return is_array($copy) ? $copy : null;
        }

        if ($value instanceof \Traversable) {
            $copy = iterator_to_array($value);

            return is_array($copy) ? $copy : null;
        }

        if (is_object($value) && method_exists($value, 'getArrayCopy')) {
            $copy = $value->getArrayCopy();

            return is_array($copy) ? $copy : null;
        }

        return null;
    }
}
