<?php

declare(strict_types=1);

namespace App\Application\Media;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class ExternalImageProxyService
{
    private const int MAX_BYTES = 15 * 1024 * 1024;
    private const int MAX_REDIRECTS = 3;
    private const int TIMEOUT_SECONDS = 15;
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly ExternalImageDnsResolverContract $dnsResolver,
    ) {
    }

    public function proxy(string $url): ExternalImageProxyResult
    {
        $currentUrl = $this->validateAndNormalizeUrl($url);

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $this->assertUrlIsSafe($currentUrl);

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withOptions([
                    // Enforce the size limit while reading, not after buffering the full body.
                    'stream' => true,
                ])
                ->withHeaders([
                    'Accept' => 'image/*',
                    'User-Agent' => 'BellugaNowExternalImageProxy/1.0',
                ])
                ->get($currentUrl);

            if ($response->status() >= 300 && $response->status() < 400) {
                $location = $response->header('Location');
                if (!is_string($location) || trim($location) === '') {
                    throw ValidationException::withMessages(['url' => 'Redirect sem destino.']);
                }
                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                continue;
            }

            if ($response->failed()) {
                throw ValidationException::withMessages([
                    'url' => 'Nao foi possivel baixar a imagem da URL informada.',
                ]);
            }

            $lengthHeader = $response->header('Content-Length');
            if (is_string($lengthHeader) && ctype_digit($lengthHeader)) {
                $length = (int) $lengthHeader;
                if ($length > self::MAX_BYTES) {
                    throw ValidationException::withMessages([
                        'url' => 'Imagem muito grande para processamento. Maximo 15MB.',
                    ]);
                }
            }

            $body = $this->readResponseBodyWithLimit($response);
            if ($body === '') {
                throw ValidationException::withMessages([
                    'url' => 'Nao foi possivel baixar a imagem da URL informada.',
                ]);
            }

            $info = @getimagesizefromstring($body);
            if (!is_array($info) || empty($info['mime']) || !is_string($info['mime'])) {
                throw ValidationException::withMessages([
                    'url' => 'Arquivo de imagem invalido. Use JPG, PNG ou WEBP.',
                ]);
            }

            $contentType = $response->header('Content-Type');
            $contentType = is_string($contentType) && str_starts_with(strtolower($contentType), 'image/')
                ? $this->stripContentTypeParams($contentType)
                : $info['mime'];

            return new ExternalImageProxyResult(
                bytes: $body,
                contentType: $contentType,
            );
        }

        throw ValidationException::withMessages([
            'url' => 'Muitos redirects ao baixar a imagem.',
        ]);
    }

    private function readResponseBodyWithLimit(\Illuminate\Http\Client\Response $response): string
    {
        $psrResponse = $response->toPsrResponse();
        $stream = $psrResponse->getBody();

        $buffer = '';
        $bytesRead = 0;
        $chunkSize = 8192;

        while (!$stream->eof()) {
            $chunk = $stream->read($chunkSize);
            if ($chunk === '') {
                break;
            }
            $bytesRead += strlen($chunk);
            if ($bytesRead > self::MAX_BYTES) {
                throw ValidationException::withMessages([
                    'url' => 'Imagem muito grande para processamento. Maximo 15MB.',
                ]);
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function validateAndNormalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        $parts = parse_url($trimmed);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw ValidationException::withMessages(['url' => 'URL invalida.']);
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages(['url' => 'URL invalida.']);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['url' => 'URL invalida.']);
        }

        return $trimmed;
    }

    private function assertUrlIsSafe(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            throw ValidationException::withMessages(['url' => 'URL invalida.']);
        }

        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost') {
            throw ValidationException::withMessages(['url' => 'URL nao permitida.']);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertIpIsPublic($host);
            return;
        }

        $ips = $this->dnsResolver->resolve($host);
        if (empty($ips)) {
            throw ValidationException::withMessages(['url' => 'Nao foi possivel resolver o host da URL.']);
        }

        foreach ($ips as $ip) {
            $this->assertIpIsPublic($ip);
        }
    }

    private function assertIpIsPublic(string $ip): void
    {
        $valid = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($valid === false) {
            throw ValidationException::withMessages(['url' => 'URL nao permitida.']);
        }
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $base = new Uri($baseUrl);
        $target = new Uri($location);
        $resolved = UriResolver::resolve($base, $target);
        return (string) $resolved;
    }

    private function stripContentTypeParams(string $contentType): string
    {
        $parts = explode(';', $contentType, 2);
        return trim($parts[0]);
    }
}
