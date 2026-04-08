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

        $injectedMetadata = implode("\n        ", [
            '<title>'.$this->escape($metadata['title'] ?? '').'</title>',
            '<meta name="description" content="'.$this->escape($metadata['description'] ?? '').'">',
            '<link rel="canonical" href="'.$this->escape($metadata['canonical_url'] ?? '').'">',
            '<meta property="og:title" content="'.$this->escape($metadata['title'] ?? '').'">',
            '<meta property="og:description" content="'.$this->escape($metadata['description'] ?? '').'">',
            '<meta property="og:image" content="'.$this->escape($metadata['image'] ?? '').'">',
            '<meta property="og:url" content="'.$this->escape($metadata['canonical_url'] ?? '').'">',
            '<meta property="og:type" content="'.$this->escape($metadata['type'] ?? 'website').'">',
            '<meta property="og:site_name" content="'.$this->escape($metadata['site_name'] ?? '').'">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="'.$this->escape($metadata['title'] ?? '').'">',
            '<meta name="twitter:description" content="'.$this->escape($metadata['description'] ?? '').'">',
            '<meta name="twitter:image" content="'.$this->escape($metadata['image'] ?? '').'">',
        ]);

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

        return [
            $configured !== '' ? $configured : null,
            base_path('../web-app/index.html'),
            '/var/www/flutter/index.html',
        ];
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
}
