<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class CanonicalImageResolutionGuardrailTest extends TestCase
{
    private string $repositoryRoot;

    /** @var array<int, string> */
    private array $scanRoots = [
        'app/Application',
        'app/Integration',
        'packages/belluga',
    ];

    /** @var array<int, string> */
    private array $resolverAllowlist = [
        'app/Application/AccountProfiles/AccountProfileHeroImageResolver.php',
        'packages/belluga/belluga_events/src/Application/Events/EventHeroImageResolver.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function test_event_and_account_hero_image_fallback_chains_are_resolver_owned(): void
    {
        $violations = [];

        foreach ($this->scanRoots as $relativePath) {
            $absolutePath = $this->repositoryRoot.DIRECTORY_SEPARATOR.$relativePath;
            if (! is_dir($absolutePath)) {
                continue;
            }

            foreach ($this->phpFilesInDirectory($absolutePath) as $filePath) {
                $relativeFilePath = $this->relativePath($filePath);
                if (! $this->shouldScanFile($relativeFilePath)) {
                    continue;
                }

                $this->collectViolationsForFile($filePath, $violations);
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Event/account hero image fallback order must be owned by canonical resolvers.\n"
            ."Use EventHeroImageResolver or AccountProfileHeroImageResolver instead of local first-present media chains.\n"
            .implode("\n", $violations)
        );
    }

    public function test_public_event_image_payload_providers_delegate_to_event_hero_image_resolver(): void
    {
        $requiredDelegates = [
            'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php',
            'app/Application/PublicWeb/PublicWebMetadataService.php',
            'app/Integration/Invites/InviteTargetReadAdapter.php',
        ];

        $violations = [];
        foreach ($requiredDelegates as $relativePath) {
            $contents = file_get_contents($this->repositoryRoot.DIRECTORY_SEPARATOR.$relativePath);
            if (! is_string($contents)) {
                $violations[] = "{$relativePath}:missing";

                continue;
            }

            if (
                ! str_contains($contents, 'EventHeroImageResolver')
                || ! str_contains($contents, '->resolveFromPayload(')
            ) {
                $violations[] = $relativePath;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Public Event image providers must delegate image selection to EventHeroImageResolver.\n"
            ."Do not expose Event image URLs from EventQueryService, metadata, or invite adapters without the canonical resolver.\n"
            .implode("\n", $violations)
        );
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function collectViolationsForFile(string $absoluteFilePath, array &$violations): void
    {
        $contents = file_get_contents($absoluteFilePath);
        if (! is_string($contents) || trim($contents) === '') {
            return;
        }

        foreach ($this->candidateChainBlocks($contents) as $block) {
            $eventSourceCount = $this->countPresentNeedles($block['source'], [
                'thumb',
                'linked_account_profiles',
                'event_parties',
                'artists',
                'venue',
            ]);
            $hasEventImageChain = $eventSourceCount >= 2
                && $this->countPresentNeedles($block['source'], [
                    'url',
                    'image',
                    'hero',
                    'cover',
                    'avatar',
                    'logo',
                ]) > 0;

            $hasAccountHeroChain = $this->countPresentNeedles($block['source'], [
                'cover_url',
                'avatar_url',
            ]) === 2;

            $hasTypeVisualFallback = $this->countPresentNeedles($block['source'], [
                'avatar_url',
                'visual',
                'type_asset_url',
            ]) >= 2;

            if (! $hasEventImageChain && ! $hasAccountHeroChain && ! $hasTypeVisualFallback) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d',
                $this->relativePath($absoluteFilePath),
                $block['line']
            );
        }
    }

    /**
     * @return array<int, array{source:string,line:int}>
     */
    private function candidateChainBlocks(string $contents): array
    {
        $blocks = [];
        foreach (['resolveImageUrl([', 'firstPresentUrl([', 'foreach (['] as $marker) {
            $offset = 0;
            while (($position = strpos($contents, $marker, $offset)) !== false) {
                $blocks[] = [
                    'source' => substr($contents, $position, 900),
                    'line' => substr_count(substr($contents, 0, $position), "\n") + 1,
                ];
                $offset = $position + strlen($marker);
            }
        }

        return $blocks;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function countPresentNeedles(string $source, array $needles): int
    {
        $count = 0;
        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                $count++;
            }
        }

        return $count;
    }

    private function shouldScanFile(string $relativeFilePath): bool
    {
        if (in_array($relativeFilePath, $this->resolverAllowlist, true)) {
            return false;
        }

        if (str_starts_with($relativeFilePath, 'packages/belluga/')) {
            return str_contains($relativeFilePath, '/src/Application/');
        }

        return true;
    }

    private function relativePath(string $absoluteFilePath): string
    {
        return str_replace(
            $this->repositoryRoot.DIRECTORY_SEPARATOR,
            '',
            $absoluteFilePath
        );
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesInDirectory(string $absoluteDirectoryPath): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDirectoryPath)
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo || ! $entry->isFile()) {
                continue;
            }

            if (strtolower($entry->getExtension()) !== 'php') {
                continue;
            }

            $paths[] = $entry->getPathname();
        }

        return $paths;
    }
}
