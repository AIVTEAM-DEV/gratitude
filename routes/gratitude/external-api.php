<?php

use App\Http\Controllers\Api\Gratitude\GratitudeController;
use Illuminate\Support\Facades\Route;

Route::prefix('gratitude')->name('gratitude.')->group(function () {
    Route::get('/all', [GratitudeController::class, 'index'])->name('index');
    Route::post('/', [GratitudeController::class, 'store'])->name('store');
    Route::get('accounts/export/{format}', [GratitudeController::class, 'exportAccounts'])
        ->whereIn('format', ['pdf', 'excel', 'print'])
        ->name('accounts.export');
    Route::get('levels', [GratitudeController::class, 'levels'])->name('levels.index');
    Route::post('levels', [GratitudeController::class, 'storeLevel'])->name('levels.store');
    Route::put('levels/{level}', [GratitudeController::class, 'updateLevel'])->name('levels.update');
    Route::get('levels/{level}/benefits', [GratitudeController::class, 'benefitsByLevel'])->name('levels.benefits');
    Route::get('benefits', [GratitudeController::class, 'benefits'])->name('benefits.index');
    Route::post('benefits', [GratitudeController::class, 'storeBenefit'])->name('benefits.store');
    Route::put('benefits/{benefit}', [GratitudeController::class, 'updateBenefit'])->name('benefits.update');
    Route::get('{gratitudeNumber}/balance', [GratitudeController::class, 'balance'])->name('balance');
    Route::get('{gratitudeNumber}/level', [GratitudeController::class, 'level'])->name('level');
    Route::get('{gratitudeNumber}/points-history', [GratitudeController::class, 'pointsHistory'])->name('points-history');
    Route::get('{gratitudeNumber}', [GratitudeController::class, 'show'])->name('show');

    // Earned Points
    Route::post('{gratitudeNumber}/earned', [GratitudeController::class, 'storeEarned'])->name('earned.store');
    Route::put('{gratitudeNumber}/earned/{id}', [GratitudeController::class, 'updateEarned'])->name('earned.update');
    Route::delete('{gratitudeNumber}/earned/{id}', [GratitudeController::class, 'destroyEarned'])->name('earned.destroy');

    // Bonus Points
    Route::post('{gratitudeNumber}/bonus', [GratitudeController::class, 'storeBonus'])->name('bonus.store');
    Route::put('{gratitudeNumber}/bonus/{id}', [GratitudeController::class, 'updateBonus'])->name('bonus.update');
    Route::delete('{gratitudeNumber}/bonus/{id}', [GratitudeController::class, 'destroyBonus'])->name('bonus.destroy');

    // Cancellations
    Route::post('{gratitudeNumber}/cancel', [GratitudeController::class, 'storeCancel'])->name('cancel.store');
    Route::delete('{gratitudeNumber}/cancel/{id}', [GratitudeController::class, 'destroyCancel'])->name('cancel.destroy');

    // Redemptions
    Route::post('{gratitudeNumber}/redeem', [GratitudeController::class, 'storeRedemption'])->name('redeem.store');
    Route::put('{gratitudeNumber}/redeem/{id}', [GratitudeController::class, 'updateRedemption'])->name('redeem.update');
    Route::delete('{gratitudeNumber}/redeem/{id}', [GratitudeController::class, 'destroyRedemption'])->name('redeem.destroy');

    // Earned Benefits
    Route::post('{gratitudeNumber}/earned-benefits', [GratitudeController::class, 'storeEarnedBenefit'])->name('earned-benefits.store');
});
