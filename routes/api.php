<?php

use App\Http\Controllers\CloudSyncController;
use App\Http\Controllers\FinanceApiController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/iam', [WebhookController::class, 'handle'])->name('api.webhooks.iam');

Route::prefix('v1')->group(function () {
    // Global CORS preflight for all /api/v1/* REST endpoints (controller-backed for route:cache support)
    Route::options('/{any}', [FinanceApiController::class, 'corsPreflight'])->where('any', '.*');

    Route::get('/plan-config', [CloudSyncController::class, 'planConfig'])->name('api.plan.config');

    Route::middleware('iam.jwt:finance.basic')->group(function () {
        Route::post('/upgrade-request', [CloudSyncController::class, 'submitUpgradeRequest'])
            ->name('api.upgrade.request');

        // Full REST API (Single Source of Truth in Admin DB)
        Route::get('/bootstrap', [FinanceApiController::class, 'bootstrap'])->name('api.finance.bootstrap');
        Route::get('/categories', [FinanceApiController::class, 'categories'])->name('api.finance.categories');

        // Wallets CRUD
        Route::get('/wallets', [FinanceApiController::class, 'listWallets'])->name('api.wallets.index');
        Route::post('/wallets', [FinanceApiController::class, 'storeWallet'])->name('api.wallets.store');
        Route::put('/wallets/{id}', [FinanceApiController::class, 'updateWallet'])->name('api.wallets.update');
        Route::delete('/wallets/{id}', [FinanceApiController::class, 'destroyWallet'])->name('api.wallets.destroy');

        // Transactions CRUD (atomic wallet balance recalculation)
        Route::get('/transactions', [FinanceApiController::class, 'listTransactions'])->name('api.transactions.index');
        Route::post('/transactions', [FinanceApiController::class, 'storeTransaction'])->name('api.transactions.store');
        Route::delete('/transactions/{id}', [FinanceApiController::class, 'destroyTransaction'])->name('api.transactions.destroy');

        // Budgets CRUD (Pro)
        Route::post('/budgets', [FinanceApiController::class, 'storeBudget'])->name('api.budgets.store');
        Route::put('/budgets/{id}', [FinanceApiController::class, 'updateBudget'])->name('api.budgets.update');
        Route::delete('/budgets/{id}', [FinanceApiController::class, 'destroyBudget'])->name('api.budgets.destroy');

        // Saving Goals CRUD + Deposit / Withdraw (Pro)
        Route::post('/saving-goals', [FinanceApiController::class, 'storeSavingGoal'])->name('api.goals.store');
        Route::put('/saving-goals/{id}', [FinanceApiController::class, 'updateSavingGoal'])->name('api.goals.update');
        Route::post('/saving-goals/{id}/deposit', [FinanceApiController::class, 'depositSavingGoal'])->name('api.goals.deposit');
        Route::post('/saving-goals/{id}/withdraw', [FinanceApiController::class, 'withdrawSavingGoal'])->name('api.goals.withdraw');
        Route::delete('/saving-goals/{id}', [FinanceApiController::class, 'destroySavingGoal'])->name('api.goals.destroy');

        // Recurring Bills CRUD + Toggle + Pay (Pro)
        Route::post('/recurring-bills', [FinanceApiController::class, 'storeRecurringBill'])->name('api.recurring.store');
        Route::put('/recurring-bills/{id}', [FinanceApiController::class, 'updateRecurringBill'])->name('api.recurring.update');
        Route::patch('/recurring-bills/{id}/toggle', [FinanceApiController::class, 'toggleRecurringBill'])->name('api.recurring.toggle');
        Route::post('/recurring-bills/{id}/pay', [FinanceApiController::class, 'payRecurringBill'])->name('api.recurring.pay');
        Route::delete('/recurring-bills/{id}', [FinanceApiController::class, 'destroyRecurringBill'])->name('api.recurring.destroy');

        // Smart Inbox CRUD + Approve / Reject (Pro)
        Route::get('/inbox', [FinanceApiController::class, 'listInbox'])->name('api.inbox.index');
        Route::post('/inbox', [FinanceApiController::class, 'storeInbox'])->name('api.inbox.store');
        Route::post('/inbox/{id}/approve', [FinanceApiController::class, 'approveInbox'])->name('api.inbox.approve');
        Route::post('/inbox/{id}/reject', [FinanceApiController::class, 'rejectInbox'])->name('api.inbox.reject');
        Route::delete('/inbox/{id}', [FinanceApiController::class, 'destroyInbox'])->name('api.inbox.destroy');

        // Legacy Sync Endpoints (kept backward-compatible, without dummy auto-seed and with delete pruning)
        Route::prefix('sync')->group(function () {
            Route::get('/pull', [CloudSyncController::class, 'pull'])->name('api.sync.pull');
            Route::post('/push', [CloudSyncController::class, 'push'])->name('api.sync.push');
        });
    });
});
