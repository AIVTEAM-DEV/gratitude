<?php

namespace App\Http\Controllers\Api\Gratitude;

use App\Http\Controllers\Controller;
use App\Services\Import\GratitudeImportService;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function import(Request $request, GratitudeImportService $gratitudeImportService)
    {
        $result = $gratitudeImportService->importExternalPayload($request->json()->all());

        return response()->json($result['data'], $result['status'] ?? 200);
    }
}
