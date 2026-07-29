<?php

use App\Http\Controllers\InternalApi\Gratitude\TermsConditions\GratitudeTermConditionInternalApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('terms-conditions')->name('terms-conditions.')->group(function () {
    Route::get('/', [GratitudeTermConditionInternalApiController::class, 'index'])->name('index');
    Route::post('/', [GratitudeTermConditionInternalApiController::class, 'store'])->name('store');
    Route::patch('/{term}/status', [GratitudeTermConditionInternalApiController::class, 'updateStatus'])->name('status');
    Route::delete('/{term}', [GratitudeTermConditionInternalApiController::class, 'destroy'])->name('destroy');
});
