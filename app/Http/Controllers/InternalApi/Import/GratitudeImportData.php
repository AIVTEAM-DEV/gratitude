<?php

namespace App\Http\Controllers\InternalApi\Import;

use App\Http\Controllers\Controller;
use App\Services\Import\GratitudeImportService;

class GratitudeImportData extends Controller
{
    public function __construct(
        protected GratitudeImportService $gratitudeImportService,
    ) {}

    public function import(?string $status = null)
    {
        return $this->importGratitudes($status);
    }

    public function importGratitudes(?string $status = null)
    {
        $this->authorizeDeveloperImport();

        return $this->jsonResult($this->gratitudeImportService->importGratitudes($status));
    }

    public function importAccountData(?string $status = null)
    {
        $this->authorizeDeveloperImport();

        return $this->jsonResult($this->gratitudeImportService->importAccountData($status));
    }

    public function importAccount(string $gratitudeNumber)
    {
        $this->authorizeDeveloperImport();

        return $this->jsonResult($this->gratitudeImportService->importAccount($gratitudeNumber));
    }

    public function importBenefits()
    {
        $this->authorizeDeveloperImport();

        return $this->jsonResult($this->gratitudeImportService->importBenefits());
    }

    private function jsonResult(array $result)
    {
        return response()->json($result['data'], $result['status'] ?? 200);
    }

    private function authorizeDeveloperImport(): void
    {
        abort_unless(request()->user()?->hasRole('Developer'), 403, 'Only developers can run imports.');
    }
}
