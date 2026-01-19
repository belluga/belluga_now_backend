<?php

use App\Http\Api\v1\Controllers\AccountController;
use App\Http\Api\v1\Controllers\AuthControllerAccount;
use App\Http\Api\v1\Controllers\TenantAppDomainController;
use App\Http\Api\v1\Controllers\DomainController;
use App\Http\Api\v1\Controllers\LandlordUserController;
use App\Http\Api\v1\Controllers\ProfileControllerTenant;
use App\Http\Api\v1\Controllers\PasswordRegistrationController;
use App\Http\Api\v1\Controllers\MeController;
use App\Http\Api\v1\Controllers\AnonymousIdentityController;
use App\Http\Api\v1\Controllers\TenantRolesController;
use App\Http\Api\v1\Controllers\TenantUsersController;
use App\Http\Api\v1\Controllers\TenantBrandingController;
use App\Http\Api\v1\Controllers\OrganizationsController;
use App\Http\Api\v1\Controllers\AccountProfilesController;
use App\Http\Api\v1\Controllers\AccountProfileTypesController;
use App\Http\Middleware\CheckTenantAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('profile')
    ->middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () {
        Route::patch('/password', [ProfileControllerTenant::class, 'updatePassword']);

        Route::patch('/', [ProfileControllerTenant::class, 'updateProfile']);

        Route::patch('/emails', [ProfileControllerTenant::class, 'addEmails']);

        Route::delete('/emails', [ProfileControllerTenant::class, 'removeEmail']);

        Route::patch('/phones', [ProfileControllerTenant::class, 'addPhones']);

        Route::delete('/phones', [ProfileControllerTenant::class, 'removePhone']);
    });

Route::prefix('anonymous')
    ->group(function () {
        Route::post('/identities', [AnonymousIdentityController::class, 'store']);
    });

Route::get('/me', [MeController::class, 'tenant'])
    ->middleware(['auth:sanctum', CheckTenantAccess::class]);


Route::prefix('auth')
    ->group(function () {

        Route::post('/login', [AuthControllerAccount::class, 'login']);

        Route::post('/register/password', PasswordRegistrationController::class);

        Route::post('/password_token', [ProfileControllerTenant::class, 'generateToken']);

        Route::post('/password_reset', [ProfileControllerTenant::class, 'resetPassword']);

    Route::middleware(['auth:sanctum', CheckTenantAccess::class])
        ->group(function () {
            Route::post('/logout', [AuthControllerAccount::class, 'logout']);

            Route::get('/token_validate', [AuthControllerAccount::class, 'loginByToken']);
        });
});


Route::prefix('domains')
    ->group(function (){
        Route::post('/', [DomainController::class, 'store']);

        Route::delete('/{domain_id}', [DomainController::class, 'destroy']);

        Route::post('/{domain_id}/restore', [DomainController::class, 'restore']);

        Route::delete('/{domain_id}/force-delete', [DomainController::class, 'forceDestroy']);
});

Route::prefix('appdomains')
    ->group(function (){
        Route::get('/', [TenantAppDomainController::class, 'index']);
        Route::post('/', [TenantAppDomainController::class, 'store']);
        Route::delete('/', [TenantAppDomainController::class, 'destroy']);
    });

// Rotas protegidas para o tenant
Route::get('/check', function () {
    return response()->json(['authenticated' => true]);
});

Route::prefix('tenant-users')
    ->group(function () {

        Route::post('/', [LandlordUserController::class, 'tenantUserManage'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:tenant-users:create,tenant-users:update']);

        Route::delete('/', [LandlordUserController::class, 'tenantUserManage'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:tenant-users:delete']);

    });

    Route::prefix('users')
    ->group(function () {

        Route::get('/', [TenantUsersController::class, 'index'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:view']);

        Route::get('/{user_id}', [TenantUsersController::class, 'show'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:view']);

        Route::delete('/{user_id}', [TenantUsersController::class, 'destroy'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:delete']);

        Route::post('/{user_id}/restore', [TenantUsersController::class, 'restore'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, "abilities:account-users:create,account-users:update,account-users:delete"]);

        Route::delete('/{user_id}/force_destroy', [TenantUsersController::class, 'forceDestroy'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, "abilities:account-users:delete"]);

    });

Route::prefix('accounts')
    ->group(function () {
        Route::get('/', [AccountController::class, 'index'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:view']);

        Route::post('/', [AccountController::class, 'store'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:create']);

        Route::prefix('{account_slug}')
            ->group(function () {
                Route::get('/', [AccountController::class, 'show'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:view']);

                Route::patch('/', [AccountController::class, 'update'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:update']);

                Route::delete('/', [AccountController::class, 'destroy'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:delete']);

                Route::post('/restore', [AccountController::class, 'restore'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:update']);

                Route::post('/force_delete', [AccountController::class, 'forceDestroy'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:delete']);

                Route::post('/users/{user_id}/roles/{role_id}', [AccountController::class, 'accountUserManage'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:update']);

                Route::delete('/users/{user_id}/roles/{role_id}', [AccountController::class, 'accountUserManage'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:update']);
            });
    });

Route::prefix('organizations')
    ->group(function () {
        Route::get('/', [OrganizationsController::class, 'index'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:view']);

        Route::post('/', [OrganizationsController::class, 'store'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:create']);

        Route::prefix('{organization_id}')
            ->group(function () {
                Route::get('/', [OrganizationsController::class, 'show'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:view']);

                Route::patch('/', [OrganizationsController::class, 'update'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:update']);

                Route::delete('/', [OrganizationsController::class, 'destroy'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:delete']);

                Route::post('/restore', [OrganizationsController::class, 'restore'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:update']);

                Route::post('/force_delete', [OrganizationsController::class, 'forceDestroy'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:delete']);
            });
    });

Route::prefix('account_profiles')
    ->group(function () {
        Route::get('/', [AccountProfilesController::class, 'index'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:view']);

        Route::post('/', [AccountProfilesController::class, 'store'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:create']);

        Route::get('/geo', [AccountProfilesController::class, 'geoIndex'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:view']);

        Route::prefix('{account_profile_id}')
            ->group(function () {
                Route::get('/', [AccountProfilesController::class, 'show'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:view']);

                Route::patch('/', [AccountProfilesController::class, 'update'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:update']);

                Route::delete('/', [AccountProfilesController::class, 'destroy'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:delete']);

                Route::post('/restore', [AccountProfilesController::class, 'restore'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:update']);

                Route::post('/force_delete', [AccountProfilesController::class, 'forceDestroy'])
                    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:delete']);
            });
    });

Route::get('/account_profile_types', [AccountProfileTypesController::class, 'index'])
    ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:account-users:view']);

Route::prefix('roles')->group(function () {
    Route::get('/', [TenantRolesController::class, 'index'])
        ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:tenant-roles:view']);

    Route::post('/', [TenantRolesController::class, 'store'])
        ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:tenant-roles:create']);

    Route::get('{role_id}', [TenantRolesController::class, 'show'])
        ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:tenant-roles:view']);

    Route::patch('{role_id}', [TenantRolesController::class, 'update'])
        ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:tenant-roles:update']);

    Route::delete('{role_id}', [TenantRolesController::class, 'destroy'])
        ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:tenant-roles:delete']);

    Route::delete('{role_id}/force_delete', [TenantRolesController::class, 'forceDestroy'])
        ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:tenant-roles:delete']);

    Route::post('{role_id}/restore', [TenantRolesController::class, 'restore'])
        ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:tenant-roles:update,tenant-roles:delete']);
});

Route::prefix('branding')->group(function () {

    Route::post('/update', [TenantBrandingController::class, 'update'])
        ->middleware(['auth:sanctum', CheckTenantAccess::class, 'abilities:tenant-branding:update']);

});
