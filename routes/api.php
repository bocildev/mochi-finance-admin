<?php

use App\Http\Controllers\CloudSyncController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/iam', [WebhookController::class, 'handle'])->name('api.webhooks.iam');

Route::prefix('v1')->group(function () {
    Route::match(['get', 'options'], '/plan-config', [CloudSyncController::class, 'planConfig'])->name('api.plan.config');

    Route::options('/upgrade-request', fn () => response('', 204))->middleware('iam.jwt');
    Route::post('/upgrade-request', [CloudSyncController::class, 'submitUpgradeRequest'])
        ->middleware('iam.jwt:finance.basic')
        ->name('api.upgrade.request');

    Route::prefix('sync')->group(function () {
        Route::options('/{any}', fn () => response('', 204))->where('any', '.*')->middleware('iam.jwt');

        // Cloud Sync untuk member Mochi Free (Trial 2 dompet), Pro, dan Admin
        Route::get('/pull', [CloudSyncController::class, 'pull'])
            ->middleware('iam.jwt:finance.basic')
            ->name('api.sync.pull');

        Route::post('/push', [CloudSyncController::class, 'push'])
            ->middleware('iam.jwt:finance.basic')
            ->name('api.sync.push');
    });
});
