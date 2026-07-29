<?php

namespace App\Http\Controllers\Gratitude\TermsConditions;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class GratitudeTermConditionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Gratitude/TermsConditions');
    }
}
