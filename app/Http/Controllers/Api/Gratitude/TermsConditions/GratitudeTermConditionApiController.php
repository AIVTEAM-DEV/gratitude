<?php

namespace App\Http\Controllers\Api\Gratitude\TermsConditions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gratitude\TermsConditions\StoreGratitudeTermConditionRequest;
use App\Http\Requests\Gratitude\TermsConditions\UpdateGratitudeTermConditionRequest;
use App\Models\Gratitude\GratitudeTermCondition;
use Illuminate\Http\JsonResponse;

class GratitudeTermConditionApiController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            GratitudeTermCondition::query()
                ->where('status', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(StoreGratitudeTermConditionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] = $data['status'] ?? true;
        $data['sort_order'] = $data['sort_order']
            ?? ((int) GratitudeTermCondition::max('sort_order') + 1);

        $term = GratitudeTermCondition::create($data);

        return response()->json([
            'message' => 'Term and condition created successfully.',
            'term' => $term,
        ], 201);
    }

    public function update(
        UpdateGratitudeTermConditionRequest $request,
        GratitudeTermCondition $term
    ): JsonResponse {
        $term->update($request->validated());

        return response()->json([
            'message' => 'Term and condition updated successfully.',
            'term' => $term->fresh(),
        ]);
    }

    public function destroy(GratitudeTermCondition $term): JsonResponse
    {
        $term->delete();

        return response()->json([
            'message' => 'Term and condition deleted successfully.',
        ]);
    }
}
