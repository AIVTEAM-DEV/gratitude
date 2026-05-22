<?php

use App\Http\Controllers\ActivityLogController as ActivityLogPageController;
use App\Http\Controllers\InternalApi\ActivityLogController as InternalActivityLogController;
use App\Http\Middleware\ValidateBearerToken;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('/logs', [ActivityLogPageController::class, 'index'])->name('logs.index');

    require __DIR__.'/users/web.php';
    require __DIR__.'/gratitude/web.php';

    // Internal API

    Route::prefix('internal-api')->name('internal-api.')->group(function () {
        require __DIR__.'/users/internal-api.php';
        require __DIR__.'/gratitude/internal-api.php';

        Route::prefix('logs')->name('logs.')->group(function () {
            Route::get('/', [InternalActivityLogController::class, 'index'])->name('index');
            Route::delete('/', [InternalActivityLogController::class, 'bulkDestroy'])->name('bulk-destroy');
            Route::delete('/prune/old', [InternalActivityLogController::class, 'prune'])->name('prune');
            Route::delete('/{activityLog}', [InternalActivityLogController::class, 'destroy'])->name('destroy');
        });
    });
});

require __DIR__.'/settings.php';

// External API (bearer token auth)
Route::prefix('api/v1')
    ->name('api.')
    ->middleware([ValidateBearerToken::class])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->group(function () {
        require __DIR__.'/gratitude/external-api.php';
    });
