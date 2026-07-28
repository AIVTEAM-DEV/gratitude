<?php

namespace App\Http\Controllers\Gratitude;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gratitude\StoreGratitudeBenefitRequest;
use App\Models\Gratitude\GratitudeBenefit;
use App\Services\Gratitude\GratitudeBenefitsService;
use Illuminate\Http\Request;

class GratitudeBenefitController extends Controller
{
    protected $benefitsService;

    public function __construct(GratitudeBenefitsService $benefitsService)
    {
        $this->benefitsService = $benefitsService;
    }

    public function index()
    {
        $benefits = GratitudeBenefit::orderBy('name')->get();

        return response()->json($benefits);
    }

    public function store(StoreGratitudeBenefitRequest $request)
    {
        $validated = $request->validated();

        $benefit = GratitudeBenefit::create([
            'name' => $validated['name'],
            'benefit_key' => $validated['benefit_key'] ?? null,
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'] ?? 'text',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (isset($validated['level_mappings'])) {
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
            $benefit->levels()->sync($syncData);
        }

        return response()->json(['message' => 'Benefit created successfully.', 'benefit' => $benefit], 201);
    }

    public function update(Request $request, GratitudeBenefit $benefit)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'benefit_key' => 'nullable|string|max:100|unique:gratitude_benefits,benefit_key,'.$benefit->id,
            'description' => 'nullable|string',
            'type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $validated['type'] = $validated['type'] ?? 'text';
        $benefit->update($validated);

        return response()->json(['message' => 'Benefit updated successfully.', 'benefit' => $benefit]);
    }

    public function destroy(GratitudeBenefit $benefit)
    {
        $benefit->delete();

        return response()->json(['message' => 'Benefit deleted successfully.']);
    }
}
