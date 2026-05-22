<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Logs/Index');
    }
}
