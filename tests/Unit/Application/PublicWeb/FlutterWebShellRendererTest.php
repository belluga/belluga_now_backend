<?php

declare(strict_types=1);

namespace Tests\Unit\Application\PublicWeb;

use App\Application\PublicWeb\FlutterWebShellRenderer;
use PHPUnit\Framework\TestCase;

class FlutterWebShellRendererTest extends TestCase
{
    private string|false $previousEnvValue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnvValue = getenv('FLUTTER_WEB_SHELL_PATH');
    }

    protected function tearDown(): void
    {
        if ($this->previousEnvValue === false) {
            putenv('FLUTTER_WEB_SHELL_PATH');
            unset($_ENV['FLUTTER_WEB_SHELL_PATH'], $_SERVER['FLUTTER_WEB_SHELL_PATH']);
        } else {
            putenv('FLUTTER_WEB_SHELL_PATH='.$this->previousEnvValue);
            $_ENV['FLUTTER_WEB_SHELL_PATH'] = $this->previousEnvValue;
            $_SERVER['FLUTTER_WEB_SHELL_PATH'] = $this->previousEnvValue;
        }

        parent::tearDown();
    }

    public function test_runtime_shell_path_override_is_read_on_each_render(): void
    {
        $firstShell = $this->createShellFile('FIRST-SHELL');
        $secondShell = $this->createShellFile('SECOND-SHELL');
        $renderer = new FlutterWebShellRenderer();

        $this->setShellPath($firstShell);
        $firstHtml = $renderer->render($this->metadata());
        self::assertStringContainsString('FIRST-SHELL', $firstHtml);

        $this->setShellPath($secondShell);
        $secondHtml = $renderer->render($this->metadata());
        self::assertStringContainsString('SECOND-SHELL', $secondHtml);
        self::assertStringNotContainsString('FIRST-SHELL', $secondHtml);

        @unlink($firstShell);
        @unlink($secondShell);
    }

    private function setShellPath(string $path): void
    {
        putenv('FLUTTER_WEB_SHELL_PATH='.$path);
        $_ENV['FLUTTER_WEB_SHELL_PATH'] = $path;
        $_SERVER['FLUTTER_WEB_SHELL_PATH'] = $path;
    }

    /**
     * @return array<string, string>
     */
    private function metadata(): array
    {
        return [
            'title' => 'Shell Title',
            'description' => 'Shell Description',
            'canonical_url' => 'https://tenant.example/parceiro/teste',
            'image' => 'https://tenant.example/media/teste.png',
            'site_name' => 'Tenant Test',
            'type' => 'profile',
        ];
    }

    private function createShellFile(string $marker): string
    {
        $path = tempnam(sys_get_temp_dir(), 'flutter-shell-');
        self::assertIsString($path);

        file_put_contents(
            $path,
            <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <title>Original {$marker}</title>
</head>
<body>{$marker}</body>
</html>
HTML
        );

        return $path;
    }
}