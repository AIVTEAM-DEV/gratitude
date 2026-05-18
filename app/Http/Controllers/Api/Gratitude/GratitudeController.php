<?php

namespace App\Http\Controllers\Api\Gratitude;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gratitude\CancelPointRequest;
use App\Http\Requests\Gratitude\StoreBonusPointRequest;
use App\Http\Requests\Gratitude\StoreEarnedPointRequest;
use App\Http\Requests\Gratitude\StoreRedemptionRequest;
use App\Http\Requests\Gratitude\UpdateBonusPointRequest;
use App\Http\Requests\Gratitude\UpdateEarnedPointRequest;
use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\Cancellation;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeBenefit;
use App\Models\Gratitude\GratitudeEarnedBenefit;
use App\Models\Gratitude\GratitudeLevel;
use App\Models\Gratitude\RedeemPoints;
use App\Services\Gratitude\BonusPointService;
use App\Services\Gratitude\CancellationService;
use App\Services\Gratitude\EarnedPointService;
use App\Services\Gratitude\GratitudeService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GratitudeController extends Controller
{
    public function __construct(
        protected EarnedPointService $earnedPointService,
        protected BonusPointService $bonusPointService,
        protected CancellationService $cancellationService,
        protected GratitudeService $gratitudeService,
    ) {}

    public function index()
    {
        $gratitudes = $this->gratitudeService->allGratitudes();
        $levels = GratitudeLevel::whereIn('name', $gratitudes->pluck('level')->filter()->unique())
            ->get()
            ->keyBy('name');

        return response()->json(
            $gratitudes
                ->map(fn (Gratitude $gratitude) => $this->formatGratitudeForExternal(
                    $gratitude,
                    $levels->get($gratitude->level)
                ))
                ->values()
        );
    }

    public function store(Request $request)
    {

        $validated = $request->validate([
            'category' => 'nullable|array',
            'category.*' => 'integer|in:1,2,3',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'client_id' => 'nullable|integer',
            'gratitude_number' => 'nullable|string|max:255|unique:gratitudes,gratitudeNumber',
        ]);

        $prefixes = ['1' => 'G', '2' => 'T', '3' => 'P'];
        $category = $validated['category'] ?? null;
        $categoryId = is_array($category) ? ($category[0] ?? null) : $category;
        $prefix = $prefixes[(string) $categoryId] ?? 'G';

        $gratitude = $this->gratitudeService->createAccount(
            array_merge($validated, ['_prefix' => $prefix])
        );

        return response()->json([
            'message' => 'Gratitude account created',
            'gratitude' => $gratitude,
            'prefix_used' => $prefix,
        ], 201);
    }

    public function show(string $gratitudeNumber)
    {
        $data = $this->gratitudeService->gratitudeDataByNumber($gratitudeNumber);

        if (! $data) {
            return response()->json(['message' => 'Gratitude account not found'], 404);
        }

        $level = $data['level_info'] ?? null;
        $data['gratitude'] = $this->formatGratitudeForExternal($data['gratitude'], $level);
        $data['earned_benefits'] = $this->earnedBenefitsFor($gratitudeNumber);
        $data['points_history'] = $this->buildPointsHistory(
            $data['earned_points'],
            $data['bonus_points'],
            $data['cancellations'],
            $data['redemptions']
        );
        $data['points_per_dollar'] = $this->redemptionPointsPerDollar($level);

        return response()->json($data);
    }

    public function balance(string $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        return response()->json([
            'gratitudeNumber' => $gratitude->gratitudeNumber,
            'balance' => [
                'total_points' => (int) $gratitude->totalPoints,
                'earned_points' => (int) $gratitude->totalEarnedPoints,
                'bonus_points' => (int) $gratitude->totalBonusPoints,
                'usable_points' => (int) $gratitude->useablePoints,
                'non_usable_points' => (int) $gratitude->nonUseablePoints,
                'remaining_points' => (int) $gratitude->totalRemainingPoints,
                'redeemed_points' => (int) $gratitude->totalRedeemedPoints,
                'cancelled_points' => (int) $gratitude->totalCancelledPoints,
                'expired_points' => (int) $gratitude->totalExpiredPoints,
                'pending_points' => (int) EarnedPoint::where('gratitudeNumber', $gratitudeNumber)
                    ->activeStatus()
                    ->whereNotNull('usable_date')
                    ->where('usable_date', '>', Carbon::now())
                    ->sum('points'),
            ],
            'last_activity_at' => $gratitude->last_activity_at?->toISOString(),
        ]);
    }

    public function level(string $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $level = GratitudeLevel::where('name', $gratitude->level)->first();

        return response()->json([
            'gratitudeNumber' => $gratitude->gratitudeNumber,
            'level' => [
                'name' => $gratitude->level,
                'obtained_at' => $gratitude->level_obtained_at?->toDateString(),
                'history' => $gratitude->levelHistory ?? [],
                'status_change' => $gratitude->statusChange,
                'status_change_reason' => $gratitude->statusChangeReason,
                'system_level_update' => (bool) $gratitude->systemLevelUpdate,
            ],
            'level_rules' => $level ? [
                'min_points' => (int) $level->min_points,
                'max_points' => $level->max_points !== null ? (int) $level->max_points : null,
                'redemption_points_per_dollar' => (float) $level->redemption_points_per_dollar,
                'partner_points_per_dollar' => (float) ($level->partner_points_per_dollar ?: $level->redemption_points_per_dollar),
                'earned_expire_days' => (int) $level->earned_expire_days,
                'bonus_expire_days' => (int) $level->bonus_expire_days,
            ] : null,
        ]);
    }

    public function benefitsByLevel(string $level)
    {
        $levelModel = GratitudeLevel::where('name', $level)->firstOrFail();

        $benefits = $levelModel->benefits()
            ->where('gratitude_benefits.is_active', true)
            ->wherePivot('is_active', true)
            ->get()
            ->map(fn ($benefit) => [
                'id' => $benefit->id,
                'name' => $benefit->name,
                'benefit_key' => $benefit->benefit_key,
                'type' => $benefit->type,
                'description' => $benefit->pivot->description ?: $benefit->description,
                'value' => $benefit->pivot->value,
                'value_type' => $benefit->pivot->value_type,
                'calculation' => $benefit->pivot->calculation,
                'web_status' => (bool) $benefit->pivot->web_status,
            ])
            ->values();

        return response()->json([
            'level' => [
                'name' => $levelModel->name,
                'min_points' => (int) $levelModel->min_points,
                'max_points' => $levelModel->max_points !== null ? (int) $levelModel->max_points : null,
            ],
            'benefits' => $benefits,
        ]);
    }

    public function pointsHistory(string $gratitudeNumber)
    {
        Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        $earnedPoints = EarnedPoint::with(['redemptions.redeemPoint'])
            ->where('gratitudeNumber', $gratitudeNumber)
            ->get();
        $bonusPoints = BonusPoint::with(['redemptions.redeemPoint'])
            ->where('gratitudeNumber', $gratitudeNumber)
            ->get();
        $cancellations = Cancellation::where('gratitudeNumber', $gratitudeNumber)->get();
        $redemptions = RedeemPoints::with('details')
            ->where('gratitudeNumber', $gratitudeNumber)
            ->get();

        return response()->json([
            'gratitudeNumber' => $gratitudeNumber,
            'history' => $this->buildPointsHistory($earnedPoints, $bonusPoints, $cancellations, $redemptions),
        ]);
    }

    // Earned Points
    public function storeEarned(StoreEarnedPointRequest $request, string $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $point = $this->earnedPointService->add($gratitude, $request->validated());

        return response()->json(['message' => 'Points added', 'point' => $point], 201);
    }

    public function updateEarned(UpdateEarnedPointRequest $request, string $gratitudeNumber, int $id)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $point = EarnedPoint::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $updated = $this->earnedPointService->update($point, $gratitude, $request->validated());

        return response()->json(['message' => 'Points updated', 'point' => $updated]);
    }

    public function destroyEarned(string $gratitudeNumber, int $id)
    {
        $point = EarnedPoint::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $this->earnedPointService->delete($point);

        return response()->json(['message' => 'Earned point deleted']);
    }

    // Bonus Points
    public function storeBonus(StoreBonusPointRequest $request, string $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $point = $this->bonusPointService->add($gratitude, $request->validated());

        return response()->json(['message' => 'Bonus points added', 'point' => $point], 201);
    }

    public function updateBonus(UpdateBonusPointRequest $request, string $gratitudeNumber, int $id)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $point = BonusPoint::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $updated = $this->bonusPointService->update($point, $gratitude, $request->validated());

        return response()->json(['message' => 'Bonus points updated', 'point' => $updated]);
    }

    public function destroyBonus(string $gratitudeNumber, int $id)
    {
        $point = BonusPoint::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $this->bonusPointService->delete($point);

        return response()->json(['message' => 'Bonus point deleted']);
    }

    // Cancellations
    public function storeCancel(CancelPointRequest $request, string $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $cancel = $this->cancellationService->cancel(
            $gratitude,
            $request->validated(),
            $request->integer('earned_point_id') ?: null,
            $request->integer('bonus_point_id') ?: null,
        );

        return response()->json(['message' => 'Points cancelled', 'cancellation' => $cancel], 201);
    }

    public function destroyCancel(string $gratitudeNumber, int $id)
    {
        $cancel = Cancellation::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $this->cancellationService->delete($cancel);

        return response()->json(['message' => 'Cancellation deleted']);
    }

    // Redemptions
    public function storeRedemption(StoreRedemptionRequest $request, string $gratitudeNumber)
    {
        $result = $this->gratitudeService->redeemPoints($gratitudeNumber, $request->validated(), $request->points);
        if (is_array($result) && isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }
        if (! $result) {
            return response()->json(['message' => 'Insufficient points or invalid request.'], 422);
        }

        return response()->json(['message' => 'Points redeemed successfully', 'redemption' => $result], 201);
    }

    public function updateRedemption(Request $request, string $gratitudeNumber, int $id)
    {
        $request->validate(['amount' => 'nullable|numeric', 'reason' => 'nullable|string']);
        $redemption = GratitudeService::updateRedemption($id, $request->all());
        GratitudeService::syncAccountBalance($gratitudeNumber);

        return response()->json(['message' => 'Redemption updated', 'redemption' => $redemption]);
    }

    // Earned Benefits
    public function storeEarnedBenefit(Request $request, string $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        $validated = $request->validate([
            'benefit_id' => 'nullable|exists:gratitude_benefits,id',
            'journey_id' => 'nullable|integer',
            'benefit_name' => 'required_without:benefit_id|string|max:255|nullable',
            'benefit_key' => 'nullable|string|max:255',
            'description' => 'required|string',
            'benefit_value' => 'nullable|string|max:255',
            'value_type' => 'nullable|string|max:255',
            'project_data' => 'nullable|array',
            'date' => 'required|date',
            'status' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        // Auto-resolve benefit_name / benefit_key from the linked benefit when omitted
        if (! empty($validated['benefit_id'])) {
            $benefit = GratitudeBenefit::find($validated['benefit_id']);
            if ($benefit) {
                $validated['benefit_name'] = $validated['benefit_name'] ?? $benefit->name;
                $validated['benefit_key'] = $validated['benefit_key'] ?? $benefit->benefit_key;
            }
        }

        $entry = GratitudeEarnedBenefit::create(array_merge($validated, [
            'gratitudeNumber' => $gratitude->gratitudeNumber,
            'status' => $validated['status'] ?? 'active',
        ]));

        return response()->json([
            'message' => 'Earned benefit recorded',
            'earned_benefit' => [
                'id' => $entry->id,
                'gratitudeNumber' => $entry->gratitudeNumber,
                'benefit_name' => $entry->benefit_name,
                'benefit_key' => $entry->benefit_key,
                'benefit_value' => $entry->benefit_value,
                'value_type' => $entry->value_type,
                'description' => $entry->description,
                'journey_id' => $entry->journey_id,
                'project_data' => $entry->project_data,
                'date' => $entry->date?->toDateString(),
                'status' => $entry->status,
                'notes' => $entry->notes,
                'created_at' => $entry->created_at?->toISOString(),
            ],
        ], 201);
    }

    public function destroyRedemption(string $gratitudeNumber, int $id)
    {
        $success = GratitudeService::deleteRedemption($id);
        if (! $success) {
            return response()->json(['message' => 'Failed to delete redemption'], 500);
        }

        return response()->json(['message' => 'Redemption deleted']);
    }

    private function buildPointsHistory($earnedPoints, $bonusPoints, $cancellations, $redemptions)
    {
        $history = collect();
        $redemptionIdsFromPointDetails = collect();

        foreach ($earnedPoints as $point) {
            $history->push($this->historyEntry('earned', $point->usable_date ?? $point->date ?? $point->created_at, $point->points, $point->description ?: 'Earned points', 'EarnedPoint', $point->id));

            foreach (($point->redemptions ?? []) as $detail) {
                $redemptionIdsFromPointDetails->push($detail->redeem_id);
                $history->push($this->historyEntry('redemption', $detail->created_at, -1 * (int) $detail->points, $detail->redeemPoint?->reason ?: 'Point redemption', 'EarnedPoint', $point->id));
            }

            if ((int) $point->expired_points > 0) {
                $history->push($this->historyEntry('expiration', $point->expires_at, -1 * (int) $point->expired_points, 'Points expired', 'EarnedPoint', $point->id));
            }
        }

        foreach ($bonusPoints as $point) {
            $history->push($this->historyEntry('bonus', $point->usable_date ?? $point->date ?? $point->created_at, $point->points, $point->description ?: 'Bonus points', 'BonusPoint', $point->id));

            foreach (($point->redemptions ?? []) as $detail) {
                $redemptionIdsFromPointDetails->push($detail->redeem_id);
                $history->push($this->historyEntry('redemption', $detail->created_at, -1 * (int) $detail->points, $detail->redeemPoint?->reason ?: 'Point redemption', 'BonusPoint', $point->id));
            }

            if ((int) $point->expired_points > 0) {
                $history->push($this->historyEntry('expiration', $point->expires_at, -1 * (int) $point->expired_points, 'Points expired', 'BonusPoint', $point->id));
            }
        }

        foreach ($cancellations as $cancel) {
            $history->push($this->historyEntry('cancellation', $cancel->date ?? $cancel->created_at, -1 * (int) $cancel->points, $cancel->description ?: 'Point cancellation', 'Cancellation', $cancel->id));
        }

        foreach ($redemptions as $redemption) {
            if ($redemptionIdsFromPointDetails->contains($redemption->id)) {
                continue;
            }

            $history->push($this->historyEntry('redemption', $redemption->created_at, -1 * (int) $redemption->points, $redemption->reason ?: 'Point redemption', 'RedeemPoints', $redemption->id));
        }

        return $history
            ->sortByDesc(fn ($entry) => $entry['sort_date'] ?? '')
            ->values()
            ->map(function ($entry) {
                unset($entry['sort_date']);

                return $entry;
            });
    }

    private function historyEntry(string $type, mixed $date, int|float|null $points, string $description, string $sourceType, int|string|null $sourceId): array
    {
        $parsedDate = $date ? Carbon::parse($date) : null;

        return [
            'type' => $type,
            'date' => $parsedDate?->toDateString(),
            'sort_date' => $parsedDate?->toISOString(),
            'points' => (int) ($points ?? 0),
            'description' => $description,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ];
    }

    private function earnedBenefitsFor(string $gratitudeNumber)
    {
        return GratitudeEarnedBenefit::where('gratitudeNumber', $gratitudeNumber)
            ->with('benefit')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    private function formatGratitudeForExternal(Gratitude $gratitude, ?GratitudeLevel $level = null): array
    {
        $pointsPerDollar = $this->redemptionPointsPerDollar($level);
        $usablePoints = (int) $gratitude->useablePoints;
        $data = $gratitude->toArray();

        $data['usable_points'] = $usablePoints;
        $data['points_per_dollar'] = $pointsPerDollar;
        $data['redemption_points_per_dollar'] = $pointsPerDollar;
        $data['usable_points_dollar_value'] = $this->dollarValueForPoints($usablePoints, $pointsPerDollar);

        return $data;
    }

    private function redemptionPointsPerDollar(?GratitudeLevel $level): float
    {
        return max(1, (float) ($level?->redemption_points_per_dollar ?: 35));
    }

    private function dollarValueForPoints(int $points, float $pointsPerDollar): float
    {
        return round($points / $pointsPerDollar, 2);
    }
}
