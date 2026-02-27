<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Http\Requests\TenantPushMessageRoutesRequest;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPushRouteTypesController
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings
    ) {
    }

    public function show(): JsonResponse
    {
        $routes = $this->pushSettings->currentMessageRoutes();

        return response()->json([
            'data' => is_array($routes) ? $routes : [],
        ]);
    }

    public function update(TenantPushMessageRoutesRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $routes = $this->mergeRoutes(
            $this->pushSettings->currentMessageRoutes(),
            $payload
        );

        $updatedRoutes = $this->pushSettings->patchMessageRoutes($request->user(), $routes);

        return response()->json([
            'data' => $updatedRoutes,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['required', 'string', 'distinct'],
        ]);

        $routes = $this->indexRoutes($this->pushSettings->currentMessageRoutes());
        foreach ($payload['keys'] as $key) {
            if (! isset($routes[$key])) {
                continue;
            }
            $routes[$key]['active'] = false;
        }

        $updatedRoutes = $this->pushSettings->patchMessageRoutes($request->user(), array_values($routes));

        return response()->json([
            'data' => $updatedRoutes,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $routes
     * @return array<int, array<string, mixed>>
     */
    private function mergeRoutes(array $existing, array $incoming): array
    {
        $indexed = $this->indexRoutes($existing);
        foreach ($incoming as $route) {
            $normalized = $this->normalizeRoute($route);
            $key = $normalized['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $indexed[$key] = $normalized;
        }

        return array_values($indexed);
    }

    /**
     * @param array<int, array<string, mixed>> $routes
     * @return array<string, array<string, mixed>>
     */
    private function indexRoutes(array $routes): array
    {
        $indexed = [];
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = $route['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $indexed[$key] = $route;
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    private function normalizeRoute(array $route): array
    {
        $path = (string) ($route['path'] ?? '');
        preg_match_all('/:([A-Za-z0-9_]+)/', $path, $matches);
        $route['path_params'] = $matches[1] ?? [];
        $route['query_params'] = $this->normalizeQueryParams($route['query_params'] ?? []);
        if (! array_key_exists('active', $route)) {
            $route['active'] = true;
        }

        return $route;
    }

    /**
     * @param mixed $queryParams
     * @return array<string, string>
     */
    private function normalizeQueryParams(mixed $queryParams): array
    {
        if (! is_array($queryParams)) {
            return [];
        }

        $isList = array_keys($queryParams) === range(0, count($queryParams) - 1);
        if ($isList) {
            $normalized = [];
            foreach ($queryParams as $param) {
                if (is_string($param) && $param !== '') {
                    $normalized[$param] = 'string';
                }
            }

            return $normalized;
        }

        $normalized = [];
        foreach ($queryParams as $key => $rule) {
            if (is_string($key) && $key !== '' && is_string($rule) && $rule !== '') {
                $normalized[$key] = $rule;
            }
        }

        return $normalized;
    }
}
