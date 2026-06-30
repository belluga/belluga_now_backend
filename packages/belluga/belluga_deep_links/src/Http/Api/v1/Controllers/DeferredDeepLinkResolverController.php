<?php

declare(strict_types=1);

namespace Belluga\DeepLinks\Http\Api\v1\Controllers;

use Belluga\DeepLinks\Application\DeferredDeepLinkResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DeferredDeepLinkResolverController extends Controller
{
    public function __construct(
        private readonly DeferredDeepLinkResolverService $resolver,
    ) {}

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'in:android,ios'],
            'install_referrer' => ['nullable', 'string'],
            'deferred_payload' => ['nullable', 'string'],
            'store_channel' => ['nullable', 'string'],
        ]);

        $payload = isset($validated['deferred_payload']) ? (string) $validated['deferred_payload'] : null;
        if ($payload === null && isset($validated['install_referrer'])) {
            $payload = (string) $validated['install_referrer'];
        }

        $result = $this->resolver->resolveDeferredPayload(
            payload: $payload,
            fallbackStoreChannel: isset($validated['store_channel']) ? (string) $validated['store_channel'] : null,
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}
