<?php

use App\Http\Api\v1\Controllers\AccountProfileMediaController;
use App\Http\Api\v1\Controllers\AccountProfileTypeMediaController;
use App\Http\Api\v1\Controllers\BrandingController;
use App\Http\Api\v1\Controllers\MapFilterImageMediaController;
use App\Http\Api\v1\Controllers\StaticAssetMediaController;
use App\Http\Api\v1\Controllers\StaticProfileTypeMediaController;
use App\Http\Controllers\TenantPublicShellController;
use Belluga\DeepLinks\Http\Web\Controllers\OpenAppRedirectController;
use Belluga\Events\Http\Api\v1\Controllers\EventMediaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {});

Route::middleware('guest')->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });
});

Route::middleware('tenant-maybe')->group(function () {
    Route::get('/parceiro/{account_profile_slug}', [TenantPublicShellController::class, 'accountProfile']);
    Route::get('/agenda/evento/{event_slug}', [TenantPublicShellController::class, 'event']);
    Route::get('/static/{asset_ref}', [TenantPublicShellController::class, 'staticAsset']);
    Route::get('/open-app', [OpenAppRedirectController::class, 'redirect']);
    Route::get('/.well-known/assetlinks.json', [BrandingController::class, 'getAssetLinks']);
    Route::get('/.well-known/apple-app-site-association', [BrandingController::class, 'getAppleAppSiteAssociation']);
    Route::get('/account-profiles/{account_profile}/avatar', [AccountProfileMediaController::class, 'avatar']);
    Route::get('/account-profiles/{account_profile}/cover', [AccountProfileMediaController::class, 'cover']);
    Route::get('/account-profile-types/{account_profile_type}/type_asset', [AccountProfileTypeMediaController::class, 'typeAsset']);
    Route::get('/static-assets/{static_asset}/avatar', [StaticAssetMediaController::class, 'avatar']);
    Route::get('/static-assets/{static_asset}/cover', [StaticAssetMediaController::class, 'cover']);
    Route::get('/static-profile-types/{static_profile_type}/type_asset', [StaticProfileTypeMediaController::class, 'typeAsset']);
    Route::get('/events/{event}/cover', [EventMediaController::class, 'cover']);
    Route::get('/map-filters/{key}/image', [MapFilterImageMediaController::class, 'show']);
    Route::get('/manifest.json', [BrandingController::class, 'getManifest']);
    Route::get('/favicon.ico', [BrandingController::class, 'getFavicon']);
    Route::get('/icon/icon-maskable-512x512.png', [BrandingController::class, 'getMaskableIcon']);
    Route::get('/icon/icon-192x192.png', [BrandingController::class, 'getIcon192']);
    Route::get('/icon/icon-512x512.png', [BrandingController::class, 'getIcon512']);
    Route::get('/icon-light.png', [BrandingController::class, 'getIconLight']);
    Route::get('/icon-dark.png', [BrandingController::class, 'getIconDark']);
    Route::get('/logo-light.png', [BrandingController::class, 'getLogoLight']);
    Route::get('/logo-dark.png', [BrandingController::class, 'getLogoDark']);
});
