<?php

use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DestinationController;
use App\Http\Controllers\Api\PostingLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API (prefix: /api)
|--------------------------------------------------------------------------
|
| Authenticate with a Sanctum personal access token:
|   POST /api/auth/token {email, password, device_name}  →  {token}
|   Authorization: Bearer <token>
|
| Every endpoint enforces the same permissions as the admin panel.
|
*/

Route::post('/auth/token', [AuthTokenController::class, 'store'])->middleware('throttle:api-auth')->name('api.auth.token');

Route::middleware(['auth:sanctum', 'active', 'plan.api', 'throttle:api'])->name('api.')->group(function () {
    Route::get('/me', [AuthTokenController::class, 'me'])->name('me');
    Route::delete('/auth/token', [AuthTokenController::class, 'destroy'])->name('auth.revoke');

    Route::apiResource('bots', BotController::class);
    Route::post('/bots/{bot}/test', [BotController::class, 'test'])->middleware('throttle:telegram-actions')->name('bots.test');

    Route::apiResource('destinations', DestinationController::class);
    Route::post('/destinations/{destination}/test', [DestinationController::class, 'test'])->middleware('throttle:telegram-actions')->name('destinations.test');

    Route::apiResource('contents', ContentController::class);

    Route::apiResource('campaigns', CampaignController::class);
    Route::prefix('/campaigns/{campaign}')->name('campaigns.')->group(function () {
        Route::post('/activate', [CampaignController::class, 'activate'])->name('activate');
        Route::post('/run', [CampaignController::class, 'run'])->middleware('throttle:telegram-actions')->name('run');
        Route::post('/pause', [CampaignController::class, 'pause'])->name('pause');
        Route::post('/resume', [CampaignController::class, 'resume'])->name('resume');
        Route::post('/stop', [CampaignController::class, 'stop'])->name('stop');
        Route::post('/restart', [CampaignController::class, 'restart'])->name('restart');
        Route::get('/logs', [CampaignController::class, 'logs'])->name('logs');
        Route::post('/items/{item}/skip', [CampaignController::class, 'skipItem'])->name('items.skip');
        Route::post('/items/{item}/retry', [CampaignController::class, 'retryItem'])->name('items.retry');
    });

    Route::get('/logs', [PostingLogController::class, 'index'])->name('logs.index');
});
