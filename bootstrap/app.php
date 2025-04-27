<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            Route::prefix('api/v1')
                ->middleware('api')
                ->group(base_path('routes/api/api_v1.php'));

            Route::prefix('api/v2')
                ->middleware('api')
                ->group(base_path('routes/api/api_v2.php'));

            Route::prefix('api')
                ->middleware('api')
                ->group(base_path('routes/api/api_'. env('API_DEFAULT_VERSION', 'v1').'.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware
            ->group('tenant', [
                \Spatie\Multitenancy\Http\Middleware\NeedsTenant::class,
                \Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession::class,
            ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (NotFoundHttpException $e) {
            return response()->json(['message' => 'Resource you are looking for was not found.'], 404);
        });
    })->create();
