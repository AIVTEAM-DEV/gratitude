<?php

use App\Http\Controllers\Gratitude\GratitudeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->name('gratitude.')->prefix('gratitude')->group(function () {
    Route::get('/', [GratitudeController::class, 'index'])->name('overview');
    Route::get('/accounts', [GratitudeController::class, 'accounts'])->name('accounts');
    Route::get('/reserve', [GratitudeController::class, 'reserve'])->name('reserve');
    Route::get('/history', [GratitudeController::class, 'history'])->name('history');
    Route::get('/levels', [GratitudeController::class, 'levels'])->name('levels');
    Route::get('/benefits', [GratitudeController::class, 'benefits'])->name('benefits');
    Route::get('/migrate-data', [GratitudeController::class, 'migrateData'])->name('migrate-data');
    Route::get('/program-level-benefits', [GratitudeController::class, 'programLevelBenefits'])->name('program-level-benefits');
    Route::get('/account/show/{gratitudeNumber}', [GratitudeController::class, 'show'])->name('account.show');
});
