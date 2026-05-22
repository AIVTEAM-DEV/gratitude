<?php

namespace App\Http\Controllers\Gratitude;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GratitudeController extends Controller
{
    /**
     * Renders the Vue frontend for the User Portal.
     */
    public function index(Request $request)
    {
        return Inertia::render('Gratitude/Index');
    }

    public function accounts(Request $request)
    {
        return Inertia::render('Gratitude/Accounts');
    }

    public function reserve(Request $request)
    {
        return Inertia::render('Gratitude/Reserve');
    }

    public function history(Request $request)
    {
        return Inertia::render('Gratitude/History');
    }

    public function levels(Request $request)
    {
        return Inertia::render('Gratitude/Levels');
    }

    public function benefits(Request $request)
    {
        return Inertia::render('Gratitude/Benefits');
    }

    public function migrateData(Request $request)
    {
        abort_unless($request->user()?->hasRole('Developer'), 403);

        return Inertia::render('Gratitude/MigrateData');
    }

    public function programLevelBenefits(Request $request)
    {
        return Inertia::render('Gratitude/ProgramLevelBenefits');
    }

    public function show(Request $request, $gratitudeNumber)
    {
        return Inertia::render('Gratitude/Show', [
            'gratitudeNumber' => $gratitudeNumber,
        ]);
    }
}
