<?php

namespace App\Http\Controllers\InternalApi\Gratitude;

use App\Http\Controllers\Controller;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeEarnedBenefit;
use App\Models\Gratitude\GratitudeLevel;
use App\Services\Gratitude\GratitudeBenefitsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EarnedBenefitController extends Controller
{
    public function __construct(
        protected GratitudeBenefitsService $gratitudeBenefitsService,
    ) {}

    public function index(Request $request, string $gratitudeNumber)
    {
        Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        $benefits = GratitudeEarnedBenefit::where('gratitudeNumber', $gratitudeNumber)
            ->with('benefit')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        return response()->json(['earned_benefits' => $benefits]);
    }

    public function store(Request $request, string $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        $validated = $request->validate([
            'benefit_id' => 'nullable|exists:gratitude_benefits,id',
            'journey_id' => 'nullable|integer',
            'benefit_name' => 'required_without:benefit_id|nullable|string|max:255',
            'benefit_key' => 'nullable|string|max:255',
            'description' => 'required_without:benefit_id|nullable|string',
            'benefit_value' => 'nullable|string|max:255',
            'value_type' => 'nullable|string|max:255',
            'project_data' => 'nullable|array',
            'date' => 'required|date',
            'status' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        if (! empty($validated['benefit_id'])) {
            $validated = $this->applyLevelBenefitDefaults($gratitude, $validated);
        }

        $entry = GratitudeEarnedBenefit::create(array_merge($validated, [
            'gratitudeNumber' => $gratitudeNumber,
            'status' => $validated['status'] ?? 'active',
        ]));

        return response()->json(['message' => 'Benefit recorded', 'earned_benefit' => $entry->load('benefit')], 201);
    }

    public function update(Request $request, string $gratitudeNumber, int $id)
    {
        $entry = GratitudeEarnedBenefit::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        $validated = $request->validate([
            'benefit_id' => 'nullable|exists:gratitude_benefits,id',
            'journey_id' => 'nullable|integer',
            'benefit_name' => 'sometimes|required_without:benefit_id|nullable|string|max:255',
            'benefit_key' => 'nullable|string|max:255',
            'description' => 'sometimes|required_without:benefit_id|nullable|string',
            'benefit_value' => 'nullable|string|max:255',
            'value_type' => 'nullable|string|max:255',
            'project_data' => 'nullable|array',
            'date' => 'sometimes|required|date',
            'status' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        if (! empty($validated['benefit_id'])) {
            $validated = $this->applyLevelBenefitDefaults($gratitude, $validated);
        }

        $entry->update($validated);

        return response()->json(['message' => 'Benefit updated', 'earned_benefit' => $entry->fresh()->load('benefit')]);
    }

    public function destroy(string $gratitudeNumber, int $id)
    {
        $entry = GratitudeEarnedBenefit::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $entry->delete();

        return response()->json(['message' => 'Benefit deleted']);
    }

    public function activityLog(string $gratitudeNumber, int $id)
    {
        $entry = GratitudeEarnedBenefit::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);

        $log = $entry->activities()
            ->latest()
            ->get()
            ->map(fn ($activity) => [
                'id' => $activity->id,
                'event' => $activity->event,
                'description' => $activity->description,
                'properties' => $activity->properties,
                'causer_type' => $activity->causer_type,
                'causer_id' => $activity->causer_id,
                'created_at' => Carbon::parse($activity->created_at)->toDateTimeString(),
            ]);

        return response()->json(['log' => $log]);
    }

    protected function applyLevelBenefitDefaults(Gratitude $gratitude, array $validated): array
    {
        $level = GratitudeLevel::where('name', $gratitude->level)->first();
        $benefit = $level?->benefits()
            ->whereKey($validated['benefit_id'])
            ->where('gratitude_benefits.is_active', true)
            ->wherePivot('is_active', true)
            ->first();

        if (! $benefit) {
            throw ValidationException::withMessages([
                'benefit_id' => ['The selected benefit is not active for this member’s gratitude level.'],
            ]);
        }

        $rule = $this->gratitudeBenefitsService->levelBenefitRule($benefit, $benefit->pivot);
        $validated['benefit_name'] = $benefit->name;
        $validated['benefit_key'] = $benefit->benefit_key;
        $validated['description'] = $validated['description']
            ?? $benefit->pivot->description
            ?? $benefit->description
            ?? $benefit->name;
        $validated['benefit_value'] = $validated['benefit_value'] ?? $rule['formatted_value'];
        $validated['value_type'] = $validated['value_type'] ?? $rule['key'];

        return $validated;
    }
}
