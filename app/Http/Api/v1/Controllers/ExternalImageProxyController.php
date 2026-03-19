<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Media\ExternalImageProxyService;
use App\Http\Api\v1\Requests\ExternalImageProxyRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class ExternalImageProxyController extends Controller
{
    public function __construct(
        private readonly ExternalImageProxyService $proxyService,
    ) {}

    public function store(ExternalImageProxyRequest $request): Response
    {
        $validated = $request->validated();
        $url = (string) ($validated['url'] ?? '');

        $result = $this->proxyService->proxy($url);

        return response($result->bytes, 200)
            ->header('Content-Type', $result->contentType)
            ->header('Cache-Control', 'no-store');
    }
}
