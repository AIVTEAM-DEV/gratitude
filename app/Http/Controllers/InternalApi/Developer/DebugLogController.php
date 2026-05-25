<?php

namespace App\Http\Controllers\InternalApi\Developer;

use App\Http\Controllers\Controller;
use App\Services\Developer\DebugLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DebugLogController extends Controller
{
    public function index(Request $request, DebugLogService $debugLogs): JsonResponse
    {
        abort_unless($request->user()?->hasRole('Developer'), 403);

        $validated = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
            'lines' => ['nullable', 'integer', 'min:50', 'max:2000'],
        ]);

        return response()->json($debugLogs->read(
            $validated['file'] ?? null,
            (int) ($validated['lines'] ?? 300),
        ));
    }

    public function destroy(Request $request, DebugLogService $debugLogs): JsonResponse
    {
        abort_unless($request->user()?->hasRole('Developer'), 403);

        $validated = $request->validate([
            'file' => ['required', 'string', 'max:255'],
        ]);

        $result = $debugLogs->clear($validated['file']);

        if ($result === null) {
            return response()->json(['message' => 'Log file could not be cleared.'], 422);
        }

        return response()->json($result);
    }
}
