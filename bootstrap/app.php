<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Spatie\Multitenancy\Exceptions\NoCurrentTenant;

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
            Route::prefix('api/v1/initialize')
                ->middleware('guest')
                ->group(base_path('routes/api/initialize.php'));

            Route::prefix('admin/api/v1')
                ->middleware('landlord')
                ->group(base_path('routes/api/landlord_api_v1.php'));

            Route::prefix('api/v1')
                ->middleware('tenant')
                ->group(base_path('routes/api/tenant_api_v1.php'));

            Route::prefix('api/v1/accounts/{account_slug}')
                ->middleware(['tenant'])
                ->group(base_path('routes/api/account_api_v1.php'));

            Route::prefix('api/v2')
//                ->middleware('api')
                ->group(base_path('routes/api/api_v2.php'));

            Route::prefix('admin/api')
                ->middleware('landlord')
                ->group(base_path('routes/api/landlord_api_'. env('API_DEFAULT_VERSION', 'v1').'.php'));

            Route::prefix('api')
                ->middleware('tenant')
                ->group(base_path('routes/api/tenant_api_'. env('API_DEFAULT_VERSION', 'v1').'.php'));

            Route::prefix('api/accounts/{account_slug}')
                ->middleware(['tenant'])
                ->group(base_path('routes/api/account_api_'. env('API_DEFAULT_VERSION', 'v1').'.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {

        $middleware
            ->group(
                "landlord",
                [
                    \App\Http\Middleware\LandlordValidation::class,
                ]
            );

        $middleware
            ->group(
                "account",
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
            return response()->json(['message' => 'Tenant not found for this host.'], 400);
        });
    })->create();
