<?php

use App\Http\Controllers\Gratitude\TermsConditions\GratitudeTermConditionController;
use Illuminate\Support\Facades\Route;

Route::get('/terms-conditions', [GratitudeTermConditionController::class, 'index'])
    ->name('terms-conditions');
