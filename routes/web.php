<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'loginSubmit'])->name('login.submit');
Route::get('/auth/login', [AuthController::class, 'redirect'])->name('auth.login');
Route::get('/auth/callback', [AuthController::class, 'callback'])->name('auth.callback');
Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

// Seluruh rute web mochi-finance-admin HANYA untuk Administrator Platform (permission: finance.admin)
Route::middleware('sso:finance.admin')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/clients', [AdminDashboardController::class, 'clients'])->name('admin.clients');
    Route::post('/clients/{sub}/tier', [AdminDashboardController::class, 'updateClientTier'])->name('admin.clients.tier');
    Route::post('/clients/{sub}/status', [AdminDashboardController::class, 'toggleClientStatus'])->name('admin.clients.status');

    Route::get('/upgrades', [AdminDashboardController::class, 'upgrades'])->name('admin.upgrades');
    Route::post('/upgrades/{id}/review', [AdminDashboardController::class, 'reviewUpgrade'])->name('admin.upgrades.review');

    Route::get('/plans', [AdminDashboardController::class, 'plans'])->name('admin.plans');
    Route::post('/plans/{key}', [AdminDashboardController::class, 'updatePlan'])->name('admin.plans.update');
});
