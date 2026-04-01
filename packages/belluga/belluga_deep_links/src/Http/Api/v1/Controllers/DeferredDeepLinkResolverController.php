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
            'store_channel' => ['nullable', 'string'],
        ]);

        $platform = (string) ($validated['platform'] ?? 'android');
        if ($platform !== 'android') {
            return response()->json([
                'data' => [
                    'status' => 'not_captured',
                    'code' => null,
                    'target_path' => '/',
                    'store_channel' => $validated['store_channel'] ?? null,
                    'failure_reason' => 'unsupported_platform',
                ],
            ]);
        }

        $result = $this->resolver->resolveAndroidInstallReferrer(
            installReferrer: isset($validated['install_referrer']) ? (string) $validated['install_referrer'] : null,
            fallbackStoreChannel: isset($validated['store_channel']) ? (string) $validated['store_channel'] : null,
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}
