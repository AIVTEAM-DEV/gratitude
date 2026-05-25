<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DebugLogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->hasRole('Developer'), 403);

        return Inertia::render('Developer/DebugLogs');
    }
}
