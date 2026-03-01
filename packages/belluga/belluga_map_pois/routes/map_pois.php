<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantAccess;
use Belluga\MapPois\Http\Api\v1\Controllers\MapPoisController;
use Illuminate\Support\Facades\Route;

$mainHost = parse_url(config('app.url'), PHP_URL_HOST);
if (! is_string($mainHost) || $mainHost === '') {
    $mainHost = (string) config('app.url');
}
$mainHost = trim($mainHost);
$tenantDomainPattern = $mainHost === ''
    ? '.+'
    : '^(?!' . preg_quote($mainHost, '/') . '$).+';

Route::domain('{tenant_domain}')
    ->where(['tenant_domain' => $tenantDomainPattern])
    ->prefix('api/v1')
    ->middleware('tenant-maybe')
    ->group(function (): void {
        Route::middleware(['auth:sanctum', CheckTenantAccess::class])
            ->group(function (): void {
                Route::get('/map/pois', [MapPoisController::class, 'index']);
                Route::get('/map/near', [MapPoisController::class, 'near']);
                Route::get('/map/filters', [MapPoisController::class, 'filters']);
            });
    });
