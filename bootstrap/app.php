<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Spatie\Multitenancy\Exceptions\NoCurrentTenant;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // api: [
        //     __DIR__.'/../routes/api_v1.php',
        //      __DIR__.'/../routes/api_v2.php'
        //     ],
        commands: __DIR__.'/../routes/console.php',
        health: '/healh',
        then: function () {
            $registerProjectRoutes = static function (string $prefix, array|string $middleware, string $path, string $label): void {
                if (file_exists($path)) {
                    Route::prefix($prefix)
                        ->middleware($middleware)
                        ->group($path);

                    return;
                }

                Log::warning('Project route file missing; routes not registered.', [
                    'label' => $label,
                    'path' => $path,
                ]);
            };

            $mainHost = parse_url(config('app.url'), PHP_URL_HOST);
            if (! is_string($mainHost) || $mainHost === '') {
                $mainHost = (string) config('app.url');
            }
            $mainHost = trim($mainHost);
            $tenantDomainPattern = $mainHost === ''
                ? '.+'
                : '^(?!'.preg_quote($mainHost, '/').'$).+';

            Route::domain($mainHost)->group(function () use ($registerProjectRoutes): void {
                Route::prefix('api/v1/initialize')
                    ->middleware('guest')
                    ->group(base_path('routes/api/initialize.php'));

                $registerProjectRoutes(
                    'api/v1/initialize',
                    'guest',
                    base_path('routes/api/project_initialize.php'),
                    'project_initialize'
                );

                Route::prefix('admin/api/v1')
                    ->middleware('landlord')
                    ->group(base_path('routes/api/landlord_api_v1.php'));

                $registerProjectRoutes(
                    'api/v1',
                    [],
                    base_path('routes/api/project_landlord_public_api_v1.php'),
                    'project_landlord_public_api_v1'
                );

                $registerProjectRoutes(
                    'admin/api/v1',
                    'landlord',
                    base_path('routes/api/project_landlord_admin_api_v1.php'),
                    'project_landlord_admin_api_v1'
                );
            });

            Route::prefix('api/v2')
//                ->middleware('api')
                ->group(base_path('routes/api/api_v2.php'));

            Route::domain('{tenant_domain}')
                ->where(['tenant_domain' => $tenantDomainPattern])
                ->group(function () use ($registerProjectRoutes): void {
                    Route::prefix('admin/api/v1')
                        ->middleware(['tenant', 'landlord'])
                        ->group(base_path('routes/api/tenant_api_v1.php'));

                    Route::prefix('api/v1')
                        ->middleware('tenant-maybe')
                        ->group(base_path('routes/api/public_tenant_maybe_api_v1.php'));

                    Route::prefix('api/v1/accounts/{account_slug}')
                        ->middleware(['tenant'])
                        ->group(base_path('routes/api/account_api_v1.php'));

                    $registerProjectRoutes(
                        'api/v1',
                        'tenant-maybe',
                        base_path('routes/api/project_tenant_public_api_v1.php'),
                        'project_tenant_public_api_v1'
                    );

                    $registerProjectRoutes(
                        'admin/api/v1',
                        ['tenant', 'landlord'],
                        base_path('routes/api/project_tenant_admin_api_v1.php'),
                        'project_tenant_admin_api_v1'
                    );

                    $registerProjectRoutes(
                        'admin/api/v1',
                        ['tenant'],
                        base_path('routes/api/project_tenant_package_admin_api_v1.php'),
                        'project_tenant_package_admin_api_v1'
                    );

                    $registerProjectRoutes(
                        'api/v1/accounts/{account_slug}',
                        ['tenant'],
                        base_path('routes/api/project_account_api_v1.php'),
                        'project_account_api_v1'
                    );
                });
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Cloudflare terminates TLS at the edge and forwards traffic through trusted proxies.
        // We trust forwarding headers only from configured proxy ranges.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '172.16.0.0/12'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        // Platform-wide API security baseline (L1/L2/L3 + idempotency + edge/origin controls).
        $middleware->append(\App\Http\Middleware\ApiSecurityHardening::class);

        $middleware
            ->group(
                'landlord',
                [
                    \App\Http\Middleware\LandlordValidation::class,
                ]
            );

        $middleware
            ->group(
                'account',
                [
                    StartSession::class,
                    \App\Http\Middleware\InitializeAccount::class,
                    \Spatie\Multitenancy\Http\Middleware\NeedsTenant::class,
                    \App\Http\Middleware\CheckUserAccess::class,
                ]
            );

        $middleware
            ->group('tenant',
                [
                    StartSession::class,
                    \App\Http\Middleware\InitializeTenancy::class,
                    \Spatie\Multitenancy\Http\Middleware\NeedsTenant::class,
                ]
            );

        $middleware
            ->group('tenant-maybe',
                [
                    StartSession::class,
                    \App\Http\Middleware\InitializeTenancy::class,
                ]
            );

        $middleware->alias([
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (NotFoundHttpException $e) {
            return response()->json(['message' => 'Resource you are looking for was not found.'], 404);
        });
        $exceptions->renderable(function (NoCurrentTenant $e) {
            return response()->json(['message' => 'Resource you are looking for was not found.'], 404);
        });
    })->create();
