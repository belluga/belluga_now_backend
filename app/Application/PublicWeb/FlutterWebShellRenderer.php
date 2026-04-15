<?php

declare(strict_types=1);

namespace App\Application\PublicWeb;

use RuntimeException;

class FlutterWebShellRenderer
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function render(array $metadata): string
    {
        $shell = $this->loadShell();

        $sanitizedShell = preg_replace(
            [
                '/<title\b[^>]*>.*?<\/title>/is',
                '/<meta\s+name=["\']description["\'][^>]*>/i',
                '/<meta\s+property=["\']og:[^"\']+["\'][^>]*>/i',
                '/<meta\s+name=["\']twitter:[^"\']+["\'][^>]*>/i',
                '/<link\s+rel=["\']canonical["\'][^>]*>/i',
            ],
            '',
            $shell
        );

        if (! is_string($sanitizedShell)) {
            $sanitizedShell = $shell;
        }

        $injectedMetadata = implode("\n        ", array_filter([
            '<title>'.$this->escape($metadata['title'] ?? '').'</title>',
            '<meta name="description" content="'.$this->escape($metadata['description'] ?? '').'">',
            '<link rel="canonical" href="'.$this->escape($metadata['canonical_url'] ?? '').'">',
            '<meta property="og:title" content="'.$this->escape($metadata['title'] ?? '').'">',
            '<meta property="og:description" content="'.$this->escape($metadata['description'] ?? '').'">',
            '<meta property="og:image" content="'.$this->escape($metadata['image'] ?? '').'">',
            $this->optionalMetaProperty('og:image:secure_url', $metadata['image_secure_url'] ?? null),
            $this->optionalMetaProperty('og:image:type', $metadata['image_type'] ?? null),
            $this->optionalMetaProperty('og:image:width', $metadata['image_width'] ?? null),
            $this->optionalMetaProperty('og:image:height', $metadata['image_height'] ?? null),
            $this->optionalMetaProperty('og:image:alt', $metadata['image_alt'] ?? null),
            '<meta property="og:url" content="'.$this->escape($metadata['canonical_url'] ?? '').'">',
            '<meta property="og:type" content="'.$this->escape($metadata['type'] ?? 'website').'">',
            '<meta property="og:site_name" content="'.$this->escape($metadata['site_name'] ?? '').'">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="'.$this->escape($metadata['title'] ?? '').'">',
            '<meta name="twitter:description" content="'.$this->escape($metadata['description'] ?? '').'">',
            '<meta name="twitter:image" content="'.$this->escape($metadata['image'] ?? '').'">',
            $this->optionalMetaName('twitter:image:alt', $metadata['image_alt'] ?? null),
        ]));

        $rendered = preg_replace(
            '/<\/head>/i',
            "        {$injectedMetadata}\n    </head>",
            $sanitizedShell,
            1
        );

        if (! is_string($rendered)) {
            throw new RuntimeException('Unable to inject public web metadata into Flutter shell.');
        }

        return $rendered;
    }

    private function loadShell(): string
    {
        foreach ($this->shellCandidates() as $candidate) {
            if ($candidate === null || $candidate === '' || ! is_file($candidate)) {
                continue;
            }

            $contents = file_get_contents($candidate);
            if (is_string($contents) && $contents !== '') {
                return $contents;
            }
        }

        throw new RuntimeException('Flutter web shell was not found for public metadata rendering.');
    }

    /**
     * @return array<int, string|null>
     */
    private function shellCandidates(): array
    {
        $configured = $this->configuredShellPath();
        $repositoryShell = $this->repositoryShellPath();

        return [
            $configured !== '' ? $configured : null,
            $repositoryShell,
            '/var/www/flutter/index.html',
        ];
    }

    private function repositoryShellPath(): ?string
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $app = app();
            if (! method_exists($app, 'basePath')) {
                return null;
            }

            $basePath = $app->basePath();
            if (! is_string($basePath) || trim($basePath) === '') {
                return null;
            }

            return $basePath.'/../web-app/index.html';
        } catch (\Throwable) {
            return null;
        }
    }

    private function configuredShellPath(): string
    {
        $candidates = [
            getenv('FLUTTER_WEB_SHELL_PATH'),
            $_ENV['FLUTTER_WEB_SHELL_PATH'] ?? null,
            $_SERVER['FLUTTER_WEB_SHELL_PATH'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function optionalMetaProperty(string $property, ?string $content): ?string
    {
        $normalized = trim((string) $content);
        if ($normalized === '') {
            return null;
        }

        return '<meta property="'.$this->escape($property).'" content="'.$this->escape($normalized).'">';
    }

    private function optionalMetaName(string $name, ?string $content): ?string
    {
        $normalized = trim((string) $content);
        if ($normalized === '') {
            return null;
        }

        return '<meta name="'.$this->escape($name).'" content="'.$this->escape($normalized).'">';
    }
}
