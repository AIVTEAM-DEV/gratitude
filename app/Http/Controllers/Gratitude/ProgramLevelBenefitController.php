<?php

namespace App\Http\Controllers\Gratitude;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gratitude\UpdateProgramLevelBenefitRequest;
use App\Models\Gratitude\GratitudeBenefit;
use App\Services\Gratitude\GratitudeBenefitsService;

class ProgramLevelBenefitController extends Controller
{
    protected $benefitsService;

    public function __construct(GratitudeBenefitsService $benefitsService)
    {
        $this->benefitsService = $benefitsService;
    }

    public function index()
    {
        return response()->json($this->benefitsService->getBenefitsGrid());
    }

    public function update(UpdateProgramLevelBenefitRequest $request, GratitudeBenefit $benefit)
    {
        $validated = $request->validated();

        $syncData = [];
        foreach ($validated['level_mappings'] as $levelId => $mapping) {
            if (isset($mapping['enabled']) && $mapping['enabled']) {
                $syncData[$levelId] = $this->benefitsService->normalizeLevelMapping(
                    $benefit,
                    $mapping,
                    "level_mappings.$levelId"
                );
            }
        }

        // We use sync to ensure only the selected ones remain.
        $benefit->levels()->sync($syncData);

        return response()->json(['message' => 'Program level benefits updated successfully.']);
    }
}
