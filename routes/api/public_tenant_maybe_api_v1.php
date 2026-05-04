<?php

use App\Http\Api\v1\Controllers\AccountProfileMediaController;
use App\Http\Api\v1\Controllers\AccountProfileTypeMediaController;
use App\Http\Api\v1\Controllers\AnonymousIdentityController;
use App\Http\Api\v1\Controllers\AuthControllerAccount;
use App\Http\Api\v1\Controllers\BrandingPublicWebMediaController;
use App\Http\Api\v1\Controllers\EnvironmentController;
use App\Http\Api\v1\Controllers\EventTypeMediaController;
use App\Http\Api\v1\Controllers\MapFilterImageMediaController;
use App\Http\Api\v1\Controllers\MeController;
use App\Http\Api\v1\Controllers\PasswordRegistrationController;
use App\Http\Api\v1\Controllers\PhoneOtpAuthController;
use App\Http\Api\v1\Controllers\ProfileControllerTenant;
use App\Http\Api\v1\Controllers\ProfileProximityPreferencesController;
use App\Http\Api\v1\Controllers\StaticAssetMediaController;
use App\Http\Api\v1\Controllers\StaticProfileTypeMediaController;
use App\Http\Api\v1\Controllers\TenantTelemetrySettingsController;
use App\Http\Middleware\CheckTenantAccess;
use App\Http\Middleware\EnsureTenantPublicAuthMethod;
use Belluga\Events\Http\Api\v1\Controllers\EventMediaController;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant')->group(function () {
    Route::get('/environment', [EnvironmentController::class, 'showEnvironmentData']);
    Route::get(
        '/media/branding-public-web/{branding_subject_id}/default_image',
        [BrandingPublicWebMediaController::class, 'defaultImage']
    );
    Route::get('/media/map-filters/{key}', [MapFilterImageMediaController::class, 'show']);
    Route::get(
        '/media/account-profiles/{account_profile_id}/avatar',
        [AccountProfileMediaController::class, 'avatar']
    );
    Route::get(
        '/media/account-profiles/{account_profile_id}/cover',
        [AccountProfileMediaController::class, 'cover']
    );
    Route::get(
        '/media/account-profile-types/{account_profile_type_id}/type_asset',
        [AccountProfileTypeMediaController::class, 'typeAsset']
    );
    Route::get(
        '/media/event-types/{event_type_id}/type_asset',
        [EventTypeMediaController::class, 'typeAsset']
    );
    Route::get(
        '/media/static-assets/{static_asset_id}/avatar',
        [StaticAssetMediaController::class, 'avatar']
    );
    Route::get(
        '/media/static-assets/{static_asset_id}/cover',
        [StaticAssetMediaController::class, 'cover']
    );
    Route::get(
        '/media/static-profile-types/{static_profile_type_id}/type_asset',
        [StaticProfileTypeMediaController::class, 'typeAsset']
    );
    Route::get(
        '/media/events/{event_id}/cover',
        [EventMediaController::class, 'cover']
    );

    Route::prefix('anonymous')
        ->group(function () {
            Route::post('/identities', [AnonymousIdentityController::class, 'store']);
        });

    Route::get('/me', [MeController::class, 'tenant'])
        ->middleware(['auth:sanctum', CheckTenantAccess::class]);

    Route::prefix('profile')
        ->middleware(['auth:sanctum', CheckTenantAccess::class])
        ->group(function () {
            Route::get('/proximity-preferences', [ProfileProximityPreferencesController::class, 'show']);
            Route::put('/proximity-preferences', [ProfileProximityPreferencesController::class, 'upsert']);
            Route::patch('/password', [ProfileControllerTenant::class, 'updatePassword'])
                ->middleware(EnsureTenantPublicAuthMethod::class.':password');

            Route::patch('/', [ProfileControllerTenant::class, 'updateProfile']);

            Route::patch('/emails', [ProfileControllerTenant::class, 'addEmails']);

            Route::delete('/emails', [ProfileControllerTenant::class, 'removeEmail']);

            Route::patch('/phones', [ProfileControllerTenant::class, 'addPhones']);

            Route::delete('/phones', [ProfileControllerTenant::class, 'removePhone']);
        });

    Route::prefix('auth')
        ->group(function () {
            Route::post('/otp/challenge', [PhoneOtpAuthController::class, 'challenge']);

            Route::post('/otp/verify', [PhoneOtpAuthController::class, 'verify']);

            Route::post('/login', [AuthControllerAccount::class, 'login'])
                ->middleware(EnsureTenantPublicAuthMethod::class.':password');

            Route::post('/register/password', PasswordRegistrationController::class)
                ->middleware(EnsureTenantPublicAuthMethod::class.':password');

            Route::post('/password_token', [ProfileControllerTenant::class, 'generateToken'])
                ->middleware(EnsureTenantPublicAuthMethod::class.':password');

            Route::post('/password_reset', [ProfileControllerTenant::class, 'resetPassword'])
                ->middleware(EnsureTenantPublicAuthMethod::class.':password');

            Route::middleware(['auth:sanctum', CheckTenantAccess::class])
                ->group(function () {
                    Route::post('/logout', [AuthControllerAccount::class, 'logout']);

                    Route::get('/token_validate', [AuthControllerAccount::class, 'loginByToken']);
                });
        });

    Route::prefix('settings')
        ->middleware(['auth:sanctum', CheckTenantAccess::class])
        ->group(function () {
            Route::get('/telemetry', [TenantTelemetrySettingsController::class, 'index'])
                ->middleware('abilities:telemetry-settings:update');
            Route::post('/telemetry', [TenantTelemetrySettingsController::class, 'store'])
                ->middleware('abilities:telemetry-settings:update');
            Route::delete('/telemetry/{type}', [TenantTelemetrySettingsController::class, 'destroy'])
                ->middleware('abilities:telemetry-settings:update');
        });
});
