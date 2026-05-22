<?php

use App\Http\Controllers\Gratitude\GratitudeLevelController;
use App\Http\Controllers\Gratitude\ProgramLevelBenefitController;
use App\Http\Controllers\InternalApi\Gratitude\EarnedBenefitController;
use App\Http\Controllers\InternalApi\Gratitude\GratitudeBenefitController;
use App\Http\Controllers\InternalApi\Gratitude\GratitudeController;
use Illuminate\Support\Facades\Route;

// Gratitude APIs

Route::name('gratitude.')->prefix('gratitude/')
    ->group(function () {
        Route::get('migrate-data/{status?}', [GratitudeController::class, 'import'])
            ->whereIn('status', ['active', 'inactive'])
            ->name('import');
        Route::get('migrate-gratitudes/{status?}', [GratitudeController::class, 'importGratitude'])
            ->whereIn('status', ['active', 'inactive'])
            ->name('import-gratitudes');
        Route::get('migrate-account-data/{status?}', [GratitudeController::class, 'importAccountsDataByStatus'])
            ->whereIn('status', ['active', 'inactive'])
            ->name('import-account-data');
        Route::get('/', [GratitudeController::class, 'apiIndex'])->name('index');
        Route::get('accounts/export/{format}', [GratitudeController::class, 'apiExportAccounts'])
            ->whereIn('format', ['pdf', 'excel', 'print'])
            ->name('accounts.export');
        Route::get('overview', [GratitudeController::class, 'apiOverview'])->name('overview');
        Route::get('reserve', [GratitudeController::class, 'apiReserve'])->name('reserve');
        Route::get('history', [GratitudeController::class, 'apiHistory'])->name('history');
        Route::get('account/show/{gratitudeNumber}', [GratitudeController::class, 'apiShow'])->name('account.show');
        Route::post('account/{gratitudeNumber}/import', [GratitudeController::class, 'apiImportAccount'])->name('account.import');
        Route::patch('account/{gratitudeNumber}/status', [GratitudeController::class, 'apiUpdateStatus'])->name('account.status');
        Route::post('{gratitudeNumber}/earned', [GratitudeController::class, 'apiAddEarned'])->name('earned');
        Route::put('{gratitudeNumber}/earned/{id}', [GratitudeController::class, 'apiUpdateEarned'])->name('earned.update');
        Route::delete('{gratitudeNumber}/earned/{id}', [GratitudeController::class, 'apiDeleteEarned'])->name('earned.destroy');
        Route::post('{gratitudeNumber}/bonus', [GratitudeController::class, 'apiAddBonus'])->name('bonus');
        Route::put('{gratitudeNumber}/bonus/{id}', [GratitudeController::class, 'apiUpdateBonus'])->name('bonus.update');
        Route::delete('{gratitudeNumber}/bonus/{id}', [GratitudeController::class, 'apiDeleteBonus'])->name('bonus.destroy');
        Route::post('{gratitudeNumber}/cancel', [GratitudeController::class, 'apiCancelPoints'])->name('cancel');
        Route::delete('{gratitudeNumber}/cancel/{id}', [GratitudeController::class, 'apiDeleteCancellation'])->name('cancel.destroy');
        Route::post('{gratitudeNumber}/expire', [GratitudeController::class, 'apiExpirePoints'])->name('expire');

        // Levels CRUD
        Route::get('level-media/{path}', [GratitudeLevelController::class, 'media'])
            ->where('path', '.*')
            ->name('levels.media');
        Route::get('levels', [GratitudeLevelController::class, 'index'])->name('levels.index');
        Route::post('levels', [GratitudeLevelController::class, 'store'])->name('levels.store');
        Route::put('levels/{level}', [GratitudeLevelController::class, 'update'])->name('levels.update');
        Route::delete('levels/{level}', [GratitudeLevelController::class, 'destroy'])->name('levels.destroy');

        // Benefits CRUD
        Route::get('benefits', [App\Http\Controllers\Gratitude\GratitudeBenefitController::class, 'index'])->name('benefits.index');
        Route::post('benefits', [App\Http\Controllers\Gratitude\GratitudeBenefitController::class, 'store'])->name('benefits.store');
        Route::put('benefits/{benefit}', [App\Http\Controllers\Gratitude\GratitudeBenefitController::class, 'update'])->name('benefits.update');
        Route::delete('benefits/{benefit}', [App\Http\Controllers\Gratitude\GratitudeBenefitController::class, 'destroy'])->name('benefits.destroy');

        // Program Level Benefits (Pivot)
        Route::get('program-benefits', [ProgramLevelBenefitController::class, 'index'])->name('program-benefits.index');
        Route::put('program-benefits/{benefit}', [ProgramLevelBenefitController::class, 'update'])->name('program-benefits.update');

        // Import Benefits
        Route::get('migrate-benefits/data', [GratitudeBenefitController::class, 'importBenefits'])->name('migrate-benefits.data');

        // Sync balance
        Route::post('{gratitudeNumber}/sync-balance', [GratitudeController::class, 'apiSyncBalance'])->name('sync-balance');
        Route::post('{gratitudeNumber}/recalculate-level', [GratitudeController::class, 'apiRecalculateLevel'])->name('recalculate-level');

        // Earned Benefits
        Route::get('{gratitudeNumber}/earned-benefits', [EarnedBenefitController::class, 'index'])->name('earned-benefits.index');
        Route::post('{gratitudeNumber}/earned-benefits', [EarnedBenefitController::class, 'store'])->name('earned-benefits.store');
        Route::put('{gratitudeNumber}/earned-benefits/{id}', [EarnedBenefitController::class, 'update'])->name('earned-benefits.update');
        Route::delete('{gratitudeNumber}/earned-benefits/{id}', [EarnedBenefitController::class, 'destroy'])->name('earned-benefits.destroy');
        Route::get('{gratitudeNumber}/earned-benefits/{id}/log', [EarnedBenefitController::class, 'activityLog'])->name('earned-benefits.log');

        // Redemption CRUD
        Route::post('{gratitudeNumber}/redeem', [GratitudeController::class, 'apiAddRedeem'])->name('redeem');
        Route::get('{gratitudeNumber}/redeem/{id}', [GratitudeController::class, 'apiShowRedemption'])->name('redeem.show');
        Route::put('{gratitudeNumber}/redeem/{id}', [GratitudeController::class, 'apiUpdateRedemption'])->name('redeem.update');
        Route::delete('{gratitudeNumber}/redeem/{id}', [GratitudeController::class, 'apiDeleteRedemption'])->name('redeem.destroy');
    });
