<?php

namespace App\Http\Controllers\InternalApi\Gratitude;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gratitude\CancelPointRequest;
use App\Http\Requests\Gratitude\StoreBonusPointRequest;
use App\Http\Requests\Gratitude\StoreEarnedPointRequest;
use App\Http\Requests\Gratitude\UpdateBonusPointRequest;
use App\Http\Requests\Gratitude\UpdateEarnedPointRequest;
use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\Cancellation;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeBenefit;
use App\Models\Gratitude\GratitudeEarnedBenefit;
use App\Models\Gratitude\GratitudeLevel;
use App\Models\Gratitude\GratitudeReserve;
use App\Models\Gratitude\RedeemPoints;
use App\Services\Gratitude\BonusPointService;
use App\Services\Gratitude\CancellationService;
use App\Services\Gratitude\EarnedPointService;
use App\Services\Gratitude\GratitudeAccountService;
use App\Services\Gratitude\GratitudeService;
use App\Services\Gratitude\TierService;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GratitudeController extends Controller
{
    public function __construct(
        protected GratitudeService $gratitudeService,
        protected GratitudeAccountService $gratitudeAccountService,
        protected EarnedPointService $earnedPointService,
        protected BonusPointService $bonusPointService,
        protected CancellationService $cancellationService,
        protected TierService $tierService,
    ) {}

    private function aivteamHttp(): PendingRequest
    {
        return Http::withoutVerifying()
            ->withToken(config('services.aivteam.access_token'))
            ->timeout(600);
    }

    public function import(?string $status = null)
    {
        return $this->importGratitude($status);
    }

    public function importGratitude(?string $status = null)
    {
        $this->prepareLongRunningImport();

        $importStatus = $this->normalizeImportStatus($status);
        $baseUrl = rtrim((string) config('services.aivteam.base_url'), '/');
        $gratitudesUrl = $baseUrl.'/api/gratitude/get/gratitude-data-all-by-status/gratitude/'.$importStatus;

        $getResponse = $this->aivteamHttp()->get($gratitudesUrl);

        if (! $getResponse->successful()) {
            return response()->json(['message' => 'Failed to fetch data from remote API All Gratitudes', 'status' => $getResponse->status()], 500);
        }

        $summaryRecords = $this->normalizeRemoteList($getResponse->json(), ['data', 'gratitudes']);

        if (empty($summaryRecords)) {
            return response()->json(['message' => 'Invalid data format or empty payload'], 400);
        }

        try {
            $importedAccounts = DB::transaction(fn () => $this->gratitudeService->importGratitudeTable($summaryRecords));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gratitude import failed: '.$e->getMessage()], 500);
        }

        return response()->json([
            'message' => ucfirst($importStatus).' gratitude table imported successfully',
            'import_status' => $importStatus,
            'summary_accounts' => count($summaryRecords),
            'imported_accounts' => $importedAccounts,
        ]);
    }

    public function importAccountsDataByStatus(?string $status = null)
    {
        $this->prepareLongRunningImport();

        $importStatus = $this->normalizeImportStatus($status);
        $baseUrl = rtrim((string) config('services.aivteam.base_url'), '/');
        $journeysMap = $this->fetchRemoteJourneysMap($baseUrl);
        $isActive = $importStatus === 'active';
        $summaryAccounts = 0;
        $detailedAccounts = 0;
        $detailFailures = [];

        $query = Gratitude::query()
            ->where('is_active', $isActive)
            ->where('importStatus', true)
            ->whereNotNull('gratitudeNumber')
            ->select([
                'id',
                'old_id',
                'gratitudeNumber',
                'guests_data',
                'totalPoints',
                'useablePoints',
                'level',
                'status',
                'statusChange',
                'importStatus',
                'is_active',
                'level_obtained_at',
                'expires_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id');

        try {
            $query->chunkById(50, function ($gratitudes) use ($baseUrl, $journeysMap, &$summaryAccounts, &$detailedAccounts, &$detailFailures) {
                $summaryRecords = $gratitudes
                    ->map(fn (Gratitude $gratitude) => $gratitude->toArray())
                    ->all();

                $summaryAccounts += count($summaryRecords);

                [$detailedRecords, $failures] = $this->fetchRemoteGratitudeAccountDetails($summaryRecords, $baseUrl);
                $detailFailures = array_merge($detailFailures, $failures);

                if ($detailedRecords === []) {
                    return;
                }

                DB::transaction(fn () => $this->gratitudeService->importAccountsData(array_values($detailedRecords), $journeysMap));
                $detailedAccounts += count($detailedRecords);
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Account data import failed: '.$e->getMessage()], 500);
        }

        try {
            $syncedAccounts = $this->gratitudeService->syncAccountBalancesFor(
                Gratitude::query()
                    ->where('is_active', $isActive)
                    ->where('importStatus', true)
                    ->whereNotNull('gratitudeNumber')
                    ->pluck('gratitudeNumber')
                    ->all()
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Account data imported, but balance sync failed: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => ucfirst($importStatus).' account data imported successfully',
            'import_status' => $importStatus,
            'summary_accounts' => $summaryAccounts,
            'detailed_accounts' => $detailedAccounts,
            'detail_failures' => count($detailFailures),
            'failed_detail_accounts' => $detailFailures,
            'synced_accounts' => $syncedAccounts,
        ]);
    }

    private function normalizeImportStatus(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, ['active', 'inactive'], true)
            ? $status
            : 'active';
    }

    private function fetchRemoteJourneysMap(string $baseUrl): array
    {
        $journeysMap = [];
        $getJourneysData = $this->aivteamHttp()->get($baseUrl.'/api/get/all/journeys');

        if (! $getJourneysData->successful()) {
            return $journeysMap;
        }

        foreach ($this->normalizeRemoteList($getJourneysData->json(), ['data', 'journeys']) as $journey) {
            if (isset($journey['id'])) {
                $journeysMap[$journey['id']] = $journey;
            }
        }

        return $journeysMap;
    }

    private function prepareLongRunningImport(): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        DB::disableQueryLog();
    }

    private function fetchRemoteGratitudeAccountDetails(array $summaryRecords, string $baseUrl): array
    {
        $detailedRecords = [];
        $failures = [];

        foreach ($summaryRecords as $summaryRecord) {
            $gratitudeNumber = $summaryRecord['gratitudeNumber'] ?? null;

            if (! is_scalar($gratitudeNumber) || trim((string) $gratitudeNumber) === '') {
                $failures[] = [
                    'gratitudeNumber' => null,
                    'message' => 'Missing gratitudeNumber in summary record',
                ];

                continue;
            }

            $gratitudeNumber = (string) $gratitudeNumber;

            try {
                $response = $this->aivteamHttp()->get(
                    $baseUrl . '/api/gratitude/get/gratitude-data-all/gratitude/' . rawurlencode($gratitudeNumber)
                );
            } catch (\Throwable $e) {
                $failures[] = [
                    'gratitudeNumber' => $gratitudeNumber,
                    'message' => $e->getMessage(),
                ];

                continue;
            }

            if (! $response->successful()) {
                $failures[] = [
                    'gratitudeNumber' => $gratitudeNumber,
                    'status' => $response->status(),
                    'message' => 'Failed to fetch detail payload',
                ];

                continue;
            }

            $payload = $response->json();
            $detailRecord = $this->normalizeRemoteGratitudeDetail($payload, $summaryRecord);

            if ($detailRecord === null) {
                $failures[] = [
                    'gratitudeNumber' => $gratitudeNumber,
                    'message' => 'Invalid detail payload',
                ];

                continue;
            }

            $guestData = $this->fetchRemoteGuestData($baseUrl, $gratitudeNumber);

            if ($guestData !== null) {
                $detailRecord['guests_data'] = $guestData;
            } else {
                $guestData = Gratitude::extractGuestsData($payload);

                if ($guestData !== []) {
                    $detailRecord['guests_data'] = $guestData;
                }
            }

            $detailedRecords[$gratitudeNumber] = $detailRecord;
        }

        return [$detailedRecords, $failures];
    }

    private function fetchRemoteGuestData(string $baseUrl, string $gratitudeNumber): ?array
    {
        try {
            $response = $this->aivteamHttp()
                ->timeout(60)
                ->get($baseUrl . '/api/gratitude/get/gratitude-by-number/' . rawurlencode($gratitudeNumber));
        } catch (\Throwable) {
            return null;
        }

        return $response->successful()
            ? Gratitude::extractGuestsData($response->json())
            : null;
    }

    private function normalizeRemoteGratitudeDetail(mixed $payload, array $summaryRecord): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        if (($payload['status'] ?? true) === false) {
            return null;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $gratitude = is_array($data['gratitude'] ?? null) ? $data['gratitude'] : null;

        if ($gratitude === null && isset($data['gratitudeNumber'])) {
            $gratitude = $data;
        }

        if (! is_array($gratitude)) {
            return null;
        }

        return array_merge($summaryRecord, $gratitude, [
            'cancellationPoints' => $this->asList($data['cancellationPoints'] ?? []),
            'earnedPoints' => $this->asList($data['earnedPoints'] ?? []),
            'bonusPoints' => $this->asList($data['bonusPoints'] ?? []),
            'redeemPoints' => $this->asList($data['redeemPoints'] ?? $data['redemptionPoints'] ?? []),
        ]);
    }

    private function mergeRemoteGratitudeRecords(array $summaryRecords, array $detailedRecords): array
    {
        return array_map(function (array $summaryRecord) use ($detailedRecords) {
            $gratitudeNumber = $summaryRecord['gratitudeNumber'] ?? null;

            if (is_scalar($gratitudeNumber) && isset($detailedRecords[(string) $gratitudeNumber])) {
                return $detailedRecords[(string) $gratitudeNumber];
            }

            return $summaryRecord;
        }, $summaryRecords);
    }

    private function normalizeRemoteList(mixed $payload, array $keys = ['data']): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $this->asList($payload);
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $this->asList($payload[$key]);
            }
        }

        return [];
    }

    public function apiIndex(Request $request)
    {
        $filters = $this->gratitudeAccountService->filters($request->query());

        return response()->json([
            'points' => $this->gratitudeAccountService->accounts($filters)->values(),
            'filters' => $filters,
            'filter_options' => $this->gratitudeAccountService->filterOptions(),
        ]);
    }

    public function apiExportAccounts(Request $request, string $format)
    {
        $filters = $this->gratitudeAccountService->filters($request->query());

        return match ($format) {
            'pdf' => $this->gratitudeAccountService->pdfResponse($filters),
            'excel' => $this->gratitudeAccountService->excelResponse($filters),
            'print' => $this->gratitudeAccountService->printResponse($filters),
            default => response()->json(['message' => 'Unsupported export format.'], 404),
        };
    }

    public function apiOverview()
    {
        $totalAccounts = Gratitude::count();
        $totalUsable = Gratitude::sum('useablePoints');
        $levelRedemptionRates = GratitudeLevel::query()
            ->pluck('redemption_points_per_dollar', 'name');
        $totalUsableAmount = Gratitude::query()
            ->select('level', DB::raw('SUM(COALESCE(useablePoints, 0)) as usable_points'))
            ->groupBy('level')
            ->get()
            ->sum(function ($accountGroup) use ($levelRedemptionRates) {
                $pointsPerDollar = max(1, (float) ($levelRedemptionRates->get($accountGroup->level) ?: 35));

                return (float) $accountGroup->usable_points / $pointsPerDollar;
            });
        $totalPending = EarnedPoint::activeStatus()
            ->whereNotNull('usable_date')
            ->where('usable_date', '>', Carbon::now())
            ->sum('points');
        $totalReserved = GratitudeReserve::sum('amount');
        $totalUsedMoney = RedeemPoints::sum('amount') > 0 ? RedeemPoints::sum('amount') : RedeemPoints::sum('points'); // Approximating used money as amount or redeemed points

        return response()->json([
            'total_accounts' => $totalAccounts,
            'total_point_balance' => Gratitude::sum('totalPoints'),
            'total_usable_points' => $totalUsable,
            'total_usable_amount' => round($totalUsableAmount, 2),
            'total_pending_points' => $totalPending,
            'total_reserved' => $totalReserved,
            'total_used_money' => $totalUsedMoney,
        ]);
    }

    public function apiReserve()
    {
        $reserves = GratitudeReserve::orderBy('created_at', 'desc')->get();

        return response()->json([
            'reserves' => $reserves,
        ]);
    }

    /**
     * Get the paginated point history (earned, bonus, redemptions).
     */
    public function apiHistory(Request $request)
    {
        $user = $request->user();

        // We can union EarnedPoint and BonusPoint or fetch them separately and merge
        // For simplicity, let's return them as distinct arrays, or the client can specify a type.

        $earned = EarnedPoint::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $bonus = BonusPoint::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'earned_points' => $earned,
            'bonus_points' => $bonus,
        ]);
    }

    public function apiShow($gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        $guests = Gratitude::normalizeGuestsData($gratitude->guests_data ?? []);

        $level = GratitudeLevel::where('name', $gratitude->level)->first();
        $benefits = [];
        if ($level) {
            $benefits = GratitudeBenefit::whereHas('levels', function ($q) use ($level) {
                $q->where('benefit_gratitude_level.gratitude_level_id', $level->id)
                    ->where('benefit_gratitude_level.is_active', true);
            })->with([
                'levels' => function ($q) use ($level) {
                    $q->where('benefit_gratitude_level.gratitude_level_id', $level->id);
                },
            ])->get()->map(function ($benefit) {
                // Simplify the output for the frontend
                $levelPivot = $benefit->levels->first();

                return [
                    'id' => $benefit->id,
                    'name' => $benefit->name,
                    'benefit_description' => $benefit->description,
                    'value' => $levelPivot ? $levelPivot->pivot->value : null,
                    'level_description' => $levelPivot ? $levelPivot->pivot->description : null,
                ];
            });
        }

        $earnedPoints = EarnedPoint::with(['cancellation', 'redemptions.redeemPoint'])->where('gratitudeNumber', $gratitudeNumber)->get();
        $bonusPoints = BonusPoint::with(['cancellation', 'redemptions.redeemPoint'])->where('gratitudeNumber', $gratitudeNumber)->get();
        $cancellations = Cancellation::where('gratitudeNumber', $gratitudeNumber)->get();
        $redemptions = RedeemPoints::with('details')->where('gratitudeNumber', $gratitudeNumber)->get();
        $journeys = $this->buildAccountJourneys($guests, $earnedPoints, $bonusPoints, $redemptions);

        $this->attachCancellationHistory($earnedPoints, $bonusPoints, $cancellations);

        // Current 2-year membership interval (mirrors TierService logic).
        $intervalYears = $level ? (int) ($level->level_interval_years ?? 2) : 2;
        $now = Carbon::now();

        $intervalStart = $gratitude->level_obtained_at
            ? Carbon::parse($gratitude->level_obtained_at)
            : ($gratitude->created_at ? Carbon::parse($gratitude->created_at) : $now->copy());

        $intervalEnd = $intervalStart->copy()->addYears($intervalYears);

        // Earned journey points usable within the interval. Redemptions do not reduce level progress.
        $rollingTotalActive = (int) EarnedPoint::where('gratitudeNumber', $gratitudeNumber)
            ->activeStatus()
            ->whereNull('cancel_id')
            ->whereNotNull('journey_id')
            ->whereNotNull('usable_date')
            ->where('usable_date', '>=', $intervalStart)
            ->where('usable_date', '<=', $now)
            ->sum(DB::raw('CASE WHEN COALESCE(points, 0) - COALESCE(cancelled_points, 0) > 0 THEN COALESCE(points, 0) - COALESCE(cancelled_points, 0) ELSE 0 END'));

        // Determine next level dynamically from the levels table (ordered lowest → highest).
        $allLevels = GratitudeLevel::where('status', true)->orderBy('min_points')->get();

        $nextLevel = null;
        $pointsToNextLevel = 0;
        $currentLevelMinPoints = $level ? (int) $level->min_points : 0;

        foreach ($allLevels as $candidateLevel) {
            if ($rollingTotalActive < (int) $candidateLevel->min_points) {
                $nextLevel = $candidateLevel->name;
                $pointsToNextLevel = (int) $candidateLevel->min_points - $rollingTotalActive;
                break;
            }
        }

        $earnedBenefits = GratitudeEarnedBenefit::where('gratitudeNumber', $gratitudeNumber)
            ->with('benefit')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $availableBenefits = GratitudeBenefit::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'benefit_key', 'description', 'type']);

        $data = [
            'gratitude' => $gratitude,
            'guests' => $guests,
            'journeys' => $journeys,
            'levels' => $allLevels->values(),
            'level_info' => $level,
            'earned_points' => $earnedPoints,
            'bonus_points' => $bonusPoints,
            'cancellations' => $cancellations,
            'redemptions' => $redemptions,
            'earned_benefits' => $earnedBenefits,
            'available_benefits' => $availableBenefits,
            'points_history' => $this->buildPointsHistory($earnedPoints, $bonusPoints, $cancellations, $redemptions, $gratitude),
            'next_level' => $nextLevel,
            'points_to_next_level' => $pointsToNextLevel,
            'rolling_tier_points' => $rollingTotalActive,
            'level_benefits' => $benefits,
            'points_per_dollar' => $level ? (float) $level->redemption_points_per_dollar : 35,
            'partner_points_per_dollar' => $level ? (float) ($level->partner_points_per_dollar ?: $level->redemption_points_per_dollar) : 35,
            'redemption_points_per_dollar' => $level ? (float) $level->redemption_points_per_dollar : 35,
            'interval_start' => $intervalStart->toDateString(),
            'interval_end' => $intervalEnd->toDateString(),
            'interval_years' => $intervalYears,
            'current_level_min' => $currentLevelMinPoints,
        ];

        return response()->json($data);
    }

    public function apiUpdateStatus(Request $request, $gratitudeNumber)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:active,inactive',
            'change_level' => 'nullable|boolean',
            'level' => 'nullable|string|exists:gratitude_levels,name',
            'reason' => 'nullable|string|max:500',
        ]);

        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        $updated = DB::transaction(function () use ($gratitude, $request, $validated) {
            $status = $validated['status'];
            $shouldChangeLevel = (bool) ($validated['change_level'] ?? false);
            $newLevel = $validated['level'] ?? $gratitude->level;
            $reason = $validated['reason'] ?? 'Manual status update';

            if ($shouldChangeLevel && $newLevel && $newLevel !== $gratitude->level) {
                $changedBy = $request->user()?->name
                    ?? $request->user()?->email
                    ?? 'admin';

                $this->tierService->setLevelManually($gratitude, $newLevel, $changedBy, $reason);
                $gratitude->refresh();
            }

            $gratitude->update([
                'status' => $status,
                'is_active' => $status === 'active',
                'last_activity_at' => Carbon::now(),
            ]);

            return $gratitude->fresh();
        });

        return response()->json([
            'message' => 'Gratitude status updated successfully.',
            'gratitude' => $updated,
        ]);
    }

    public function apiAddEarned(StoreEarnedPointRequest $request, $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $point = $this->earnedPointService->add($gratitude, $request->validated());

        return response()->json(['message' => 'Points added', 'point' => $point]);
    }

    public function apiUpdateEarned(UpdateEarnedPointRequest $request, $gratitudeNumber, $id)
    {
        $point = EarnedPoint::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $updated = $this->earnedPointService->update($point, $gratitude, $request->validated());

        return response()->json(['message' => 'Points updated', 'point' => $updated]);
    }

    public function apiAddBonus(StoreBonusPointRequest $request, $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $point = $this->bonusPointService->add($gratitude, $request->validated());

        return response()->json(['message' => 'Bonus points added', 'point' => $point]);
    }

    public function apiUpdateBonus(UpdateBonusPointRequest $request, $gratitudeNumber, $id)
    {
        $point = BonusPoint::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $updated = $this->bonusPointService->update($point, $gratitude, $request->validated());

        return response()->json(['message' => 'Bonus points updated', 'point' => $updated]);
    }

    public function apiCancelPoints(CancelPointRequest $request, $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $cancel = $this->cancellationService->cancel(
            $gratitude,
            $request->validated(),
            $request->integer('earned_point_id') ?: null,
            $request->integer('bonus_point_id') ?: null,
        );

        return response()->json(['message' => 'Points cancelled', 'cancellation' => $cancel]);
    }

    public function apiDeleteEarned($gratitudeNumber, $id)
    {
        $point = EarnedPoint::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $this->earnedPointService->delete($point);

        return response()->json(['message' => 'Earned point deleted']);
    }

    public function apiDeleteBonus($gratitudeNumber, $id)
    {
        $point = BonusPoint::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $this->bonusPointService->delete($point);

        return response()->json(['message' => 'Bonus point deleted']);
    }

    public function apiDeleteCancellation($gratitudeNumber, $id)
    {
        $cancel = Cancellation::where('gratitudeNumber', $gratitudeNumber)->findOrFail($id);
        $this->cancellationService->delete($cancel);

        return response()->json(['message' => 'Cancellation deleted']);
    }

    public function apiExpirePoints(Request $request, $gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        $request->validate(['date' => 'required', 'points' => 'required|numeric']);

        $cancel = $this->cancellationService->expire($gratitude, $request->all());

        return response()->json(['message' => 'Points expired', 'cancellation' => $cancel]);
    }

    public function apiAddRedeem(Request $request, $gratitudeNumber)
    {
        $request->validate([
            'points' => 'required|numeric|min:1',
            'amount' => 'nullable|numeric',
            'reason' => 'nullable|string',
            'redemption_type' => 'nullable|string|in:journey,partner,other',
            'journey_id' => 'nullable|integer|required_if:redemption_type,journey',
            'journey_data' => 'nullable|array',
        ]);

        $result = $this->gratitudeService->redeemPoints($gratitudeNumber, $request->all(), $request->points);

        if (is_array($result) && isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        if (! $result) {
            return response()->json(['message' => 'Insufficient points or invalid request.'], 400);
        }

        // syncAccountBalance is already called inside redeemPoints(); no second call needed.
        return response()->json(['message' => 'Points redeemed successfully!', 'redemption' => $result]);
    }

    public function apiShowRedemption($gratitudeNumber, $id)
    {
        $redemption = RedeemPoints::with('details.source')->findOrFail($id);

        return response()->json($redemption);
    }

    public function apiUpdateRedemption(Request $request, $gratitudeNumber, $id)
    {
        $request->validate([
            'amount' => 'nullable|numeric',
            'reason' => 'nullable|string',
        ]);

        $redemption = GratitudeService::updateRedemption($id, $request->all());
        GratitudeService::syncAccountBalance($gratitudeNumber);

        return response()->json(['message' => 'Redemption updated', 'redemption' => $redemption]);
    }

    public function apiDeleteRedemption($gratitudeNumber, $id)
    {
        $success = GratitudeService::deleteRedemption($id);
        if (! $success) {
            return response()->json(['message' => 'Failed to delete redemption'], 500);
        }

        return response()->json(['message' => 'Redemption removed securely']);
    }

    public function apiSyncBalance($gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        GratitudeService::syncAccountBalance($gratitude->gratitudeNumber);
        $gratitude->refresh();

        return response()->json([
            'message' => 'Balance synced successfully.',
            'useablePoints' => $gratitude->useablePoints,
            'totalPoints' => $gratitude->totalPoints,
        ]);
    }

    public function apiRecalculateLevel($gratitudeNumber)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $updated = $this->tierService->recalculateTier($gratitude->gratitudeNumber, 'manual_recalculation');

        return response()->json([
            'message' => 'Gratitude level recalculated successfully.',
            'gratitude' => $updated,
        ]);
    }

    private function buildAccountJourneys(array $guests, $earnedPoints, $bonusPoints, $redemptions): array
    {
        $journeys = [];

        $pushJourney = function (array $journey, ?array $guest, string $source) use (&$journeys) {
            $journeyId = $this->firstFilled($journey, ['id', 'journey_id', 'journeyId', 'project_id', 'projectId']);

            if (! $journeyId) {
                return;
            }

            $guestId = $guest
                ? $this->guestId($guest)
                : $this->firstFilled($journey, ['guest_id', 'guestId', 'traveller_id', 'traveler_id', 'customer_id']);

            $guestName = $guest ? $this->guestName($guest) : $this->firstFilled($journey, ['guest_name', 'guestName', 'traveller_name', 'traveler_name']);
            $key = ($guestId ?: 'account') . '#' . $journeyId;

            $normalized = [
                'id' => $journeyId,
                'journey_id' => $journeyId,
                'label' => $this->journeyLabel($journey, $journeyId),
                'project_number' => $this->firstFilled($journey, ['projectNumber', 'project_number', 'number', 'code']),
                'name' => $this->firstFilled($journey, ['name', 'title', 'project_name', 'projectName']),
                'startDate' => $this->firstFilled($journey, ['startDate', 'start_date', 'departureDate', 'departure_date', 'date_start']),
                'endDate' => $this->firstFilled($journey, ['endDate', 'end_date', 'returnDate', 'return_date', 'date_end']),
                'guest_id' => $guestId,
                'guest_name' => $guestName,
                'source' => $source,
                'raw' => $journey,
            ];

            $journeys[$key] = array_filter($normalized, fn($value) => $value !== null && $value !== '');
        };

        foreach ($guests as $guest) {
            if (! is_array($guest)) {
                continue;
            }

            foreach ($this->extractJourneysFromGuest($guest) as $journey) {
                $pushJourney($journey, $guest, 'guest');
            }
        }

        foreach ($earnedPoints as $point) {
            if (! $point->journey_id) {
                continue;
            }

            $journey = is_array($point->project_data) ? $point->project_data : [];
            $journey['id'] = $journey['id'] ?? $point->journey_id;
            $pushJourney($journey, null, 'earned_point');
        }

        foreach ($bonusPoints as $point) {
            if (! $point->journey_id) {
                continue;
            }

            $breakdown = is_array($point->points_breakdown) ? $point->points_breakdown : [];
            $journey = is_array($breakdown['journey_data'] ?? null) ? $breakdown['journey_data'] : [];
            $journey['id'] = $journey['id'] ?? $point->journey_id;
            $pushJourney($journey, null, 'bonus_point');
        }

        foreach ($redemptions as $redemption) {
            if (! $redemption->journey_id) {
                continue;
            }

            $breakdown = is_array($redemption->points_breakdown) ? $redemption->points_breakdown : [];
            $journey = is_array($breakdown['journey_data'] ?? null) ? $breakdown['journey_data'] : [];
            $journey['id'] = $journey['id'] ?? $redemption->journey_id;
            $pushJourney($journey, null, 'redemption');
        }

        return collect($journeys)
            ->sortBy(fn($journey) => ($journey['guest_name'] ?? '') . '|' . ($journey['label'] ?? ''))
            ->values()
            ->all();
    }

    private function extractJourneysFromGuest(array $guest): array
    {
        $journeys = [];

        foreach (['journeys', 'projects', 'bookings', 'reservations', 'trips', 'travels', 'guest_journeys'] as $key) {
            foreach ($this->asList($guest[$key] ?? null) as $journey) {
                $journeys[] = $journey;
            }
        }

        if (
            $this->firstFilled($guest, ['journey_id', 'journeyId', 'project_id', 'projectId'])
            && $this->firstFilled($guest, ['endDate', 'end_date', 'projectNumber', 'project_number', 'projectName', 'project_name'])
        ) {
            $journeys[] = $guest;
        }

        return $journeys;
    }

    private function asList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            $values = array_values($value);
            $items = $values && collect($values)->every(fn($item) => is_array($item))
                ? $values
                : [$value];
        } else {
            $items = $value;
        }

        return array_values(array_filter($items, fn($item) => is_array($item)));
    }

    private function firstFilled(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    private function guestId(array $guest): mixed
    {
        return $this->firstFilled($guest, ['guest_id', 'guestId', 'id', 'user_id', 'customer_id']);
    }

    private function guestName(array $guest): ?string
    {
        $name = trim(implode(' ', array_filter([
            $guest['preferred_name'] ?? null,
            $guest['first_name'] ?? null,
            $guest['last_name'] ?? null,
        ])));

        return $name !== ''
            ? $name
            : $this->firstFilled($guest, ['name', 'full_name', 'guest_name']);
    }

    private function journeyLabel(array $journey, mixed $journeyId): string
    {
        $title = trim(implode(' - ', array_filter([
            $this->firstFilled($journey, ['projectNumber', 'project_number', 'number', 'code']),
            $this->firstFilled($journey, ['name', 'title', 'project_name', 'projectName']),
        ])));

        $date = $this->firstFilled($journey, ['endDate', 'end_date', 'returnDate', 'return_date', 'date_end']);

        if ($title === '') {
            $title = 'Journey #' . $journeyId;
        }

        return $date ? "{$title} ({$date})" : $title;
    }

    private function attachCancellationHistory($earnedPoints, $bonusPoints, $cancellations): void
    {
        $pointsByKey = [];

        foreach ($earnedPoints as $point) {
            $point->setAttribute('cancellations_list', []);
            $pointsByKey[EarnedPoint::class . '#' . $point->id] = $point;
        }

        foreach ($bonusPoints as $point) {
            $point->setAttribute('cancellations_list', []);
            $pointsByKey[BonusPoint::class . '#' . $point->id] = $point;
        }

        foreach ($cancellations as $cancellation) {
            foreach (($cancellation->points_breakdown ?? []) as $allocation) {
                $key = ($allocation['source_type'] ?? '') . '#' . ($allocation['source_id'] ?? '');
                if (! isset($pointsByKey[$key])) {
                    continue;
                }

                $list = $pointsByKey[$key]->getAttribute('cancellations_list') ?? [];
                $list[] = [
                    'id' => $cancellation->id,
                    'date' => $cancellation->date,
                    'description' => $cancellation->description,
                    'points' => (int) ($allocation['points'] ?? $cancellation->points),
                ];
                $pointsByKey[$key]->setAttribute('cancellations_list', $list);
            }
        }
    }

    private function buildPointsHistory($earnedPoints, $bonusPoints, $cancellations, $redemptions, ?Gratitude $gratitude = null)
    {
        $history = collect();

        foreach ($earnedPoints as $point) {
            $history->push($this->historyEntry('earned', $point->usable_date ?? $point->date ?? $point->created_at, $point->points, $point->description ?: 'Earned points', 'EarnedPoint', $point->id));

            foreach (($point->redemptions ?? []) as $detail) {
                $history->push($this->historyEntry('redemption', $detail->created_at, -1 * (int) $detail->points, $detail->redeemPoint?->reason ?: 'Point redemption', 'EarnedPoint', $point->id));
            }

            foreach (($point->cancellations_list ?? []) as $cancel) {
                $history->push($this->historyEntry('cancellation', $cancel['date'] ?? null, -1 * (int) ($cancel['points'] ?? 0), $cancel['description'] ?: 'Point cancellation', 'EarnedPoint', $point->id));
            }

            if ((int) $point->expired_points > 0) {
                $history->push($this->historyEntry('expiration', $point->expires_at, -1 * (int) $point->expired_points, 'Points expired', 'EarnedPoint', $point->id));
            }
        }

        foreach ($bonusPoints as $point) {
            $history->push($this->historyEntry('bonus', $point->usable_date ?? $point->date ?? $point->created_at, $point->points, $point->description ?: 'Bonus points', 'BonusPoint', $point->id));

            foreach (($point->redemptions ?? []) as $detail) {
                $history->push($this->historyEntry('redemption', $detail->created_at, -1 * (int) $detail->points, $detail->redeemPoint?->reason ?: 'Point redemption', 'BonusPoint', $point->id));
            }

            foreach (($point->cancellations_list ?? []) as $cancel) {
                $history->push($this->historyEntry('cancellation', $cancel['date'] ?? null, -1 * (int) ($cancel['points'] ?? 0), $cancel['description'] ?: 'Point cancellation', 'BonusPoint', $point->id));
            }

            if ((int) $point->expired_points > 0) {
                $history->push($this->historyEntry('expiration', $point->expires_at, -1 * (int) $point->expired_points, 'Points expired', 'BonusPoint', $point->id));
            }
        }

        $allocatedCancellationIds = $cancellations
            ->filter(fn($cancel) => ! empty($cancel->points_breakdown))
            ->pluck('id')
            ->all();

        foreach ($cancellations->whereNotIn('id', $allocatedCancellationIds) as $cancel) {
            $history->push($this->historyEntry('cancellation', $cancel->date ?? $cancel->created_at, -1 * (int) $cancel->points, $cancel->description ?: 'Point cancellation', 'Cancellation', $cancel->id));
        }

        // Inject level change events so the timeline shows upgrades and downgrades inline
        if ($gratitude && is_array($gratitude->levelHistory)) {
            foreach ($gratitude->levelHistory as $entry) {
                $changeType = $entry['changeType'] ?? 'maintained';
                $entryDate = $entry['date'] ?? null;
                $parsedDate = $entryDate ? Carbon::parse($entryDate) : null;

                $history->push([
                    'type' => 'level_' . $changeType,
                    'date' => $parsedDate?->toDateString(),
                    'sort_date' => $parsedDate?->toISOString() ?? '',
                    'points' => 0,
                    'description' => $entry['reason'] ?? 'Level changed',
                    'source_type' => 'LevelHistory',
                    'source_id' => null,
                    'level_from' => $entry['fromLevel'] ?? null,
                    'level_to' => $entry['toLevel'] ?? null,
                    'change_type' => $changeType,
                    'earned_points_at_change' => (int) ($entry['earnedPoints'] ?? 0),
                ]);
            }
        }

        return $history
            ->sortByDesc(fn($entry) => $entry['sort_date'] ?? '')
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
}
