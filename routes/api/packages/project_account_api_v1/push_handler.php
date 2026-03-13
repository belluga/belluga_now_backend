<?php

declare(strict_types=1);

use App\Http\Middleware\InitializeAccount;
use Belluga\PushHandler\Http\Controllers\Account\PushMessageActionController;
use Belluga\PushHandler\Http\Controllers\Account\PushMessageController;
use Belluga\PushHandler\Http\Controllers\Account\PushMessageDataController;
use Belluga\PushHandler\Http\Controllers\Account\PushMessageSendController;
use Belluga\PushHandler\Http\Controllers\Account\PushQuotaCheckController;
use Illuminate\Support\Facades\Route;

$accountMessagesPrefix = 'push/messages';

Route::middleware('auth:sanctum')
    ->group(function () use ($accountMessagesPrefix): void {
        Route::get('/push/quota-check', PushQuotaCheckController::class)
            ->middleware('account', 'abilities:push-messages:send');

        Route::prefix($accountMessagesPrefix)
            ->group(function (): void {
                Route::get('/', [PushMessageController::class, 'index'])
                    ->middleware('account', 'abilities:push-messages:read');
                Route::post('/', [PushMessageController::class, 'store'])
                    ->middleware('account', 'abilities:push-messages:create');
                Route::get('/{push_message_id}', [PushMessageController::class, 'show'])
                    ->middleware('account', 'abilities:push-messages:read');
                Route::patch('/{push_message_id}', [PushMessageController::class, 'update'])
                    ->middleware('account', 'abilities:push-messages:update');
                Route::delete('/{push_message_id}', [PushMessageController::class, 'destroy'])
                    ->middleware('account', 'abilities:push-messages:delete');

                Route::get('/{push_message_id}/data', [PushMessageDataController::class, 'show'])
                    ->middleware(InitializeAccount::class);
                Route::post('/{push_message_id}/actions', [PushMessageActionController::class, 'store'])
                    ->middleware(InitializeAccount::class);
                Route::post('/{push_message_id}/send', PushMessageSendController::class)
                    ->middleware('account', 'abilities:push-messages:send');
            });
    });
