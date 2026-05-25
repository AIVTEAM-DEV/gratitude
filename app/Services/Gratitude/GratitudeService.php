<?php

namespace App\Services\Gratitude;

use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\Cancellation;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeLevel;
use App\Models\Gratitude\RedeemPoints;
use App\Models\Gratitude\RedeemPointsDetails;
use App\Services\Import\GratitudeImportService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GratitudeService
{
    public function __construct(
        protected PointLedgerService $pointLedgerService,
        protected GratitudeImportService $gratitudeImportService,
    ) {}

    public function createAccount(array $data = []): Gratitude
    {
        return DB::transaction(function () use ($data) {
            $levelObtainedAt = ! empty($data['level_obtained_at'])
                ? Carbon::parse($data['level_obtained_at'])
                : Carbon::now();

            $level = $this->defaultLevelName();
            $gratitudeNumber = $data['gratitudeNumber']
                ?? $data['gratitude_number']
                ?? $this->generateGratitudeNumber($data['_prefix'] ?? 'G');
            $status = $this->normalizeAccountStatus($data['status'] ?? 'active');

            return Gratitude::create([
                'gratitudeNumber' => $gratitudeNumber,
                'totalPoints' => 0,
                'totalEarnedPoints' => 0,
                'totalBonusPoints' => 0,
                'totalExpiredPoints' => 0,
                'totalCancelledPoints' => 0,
                'totalRedeemedPoints' => 0,
                'totalRemainingPoints' => 0,
                'useablePoints' => 0,
                'nonUseablePoints' => 0,
                'level' => $level,
                'levelHistory' => [
                    [
                        'fromLevel' => $level,
                        'toLevel' => $level,
                        'changeType' => 'initial',
                        'date' => $levelObtainedAt->toDateString(),
                        'earnedPoints' => 0,
                        'journeyCount' => 0,
                        'changedBy' => 'external_api',
                        'reason' => 'Initial Gratitude level assigned',
                    ],
                ],
                'level_obtained_at' => $levelObtainedAt,
                'status' => $status,
                'statusChange' => $data['statusChange'] ?? null,
                'statusChangeReason' => $data['statusChangeReason'] ?? null,
                'systemLevelUpdate' => $data['systemLevelUpdate'] ?? true,
                'is_active' => $data['is_active'] ?? $status === 'active',
                'importStatus' => $data['importStatus'] ?? false,
                'expires_at' => ! empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                'last_activity_at' => Carbon::now(),
                'guests_data' => Gratitude::extractGuestsData($data),
            ]);
        });
    }

    public function generateGratitudeNumber(string $prefix = 'G'): string
    {
        $prefix = strtoupper($prefix);

        $numbers = Gratitude::whereNotNull('gratitudeNumber')
            ->lockForUpdate()
            ->pluck('gratitudeNumber');

        $highestNumber = $numbers->reduce(function (int $highest, ?string $gratitudeNumber) use ($prefix) {
            if (! $gratitudeNumber || ! preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $gratitudeNumber, $matches)) {
                return $highest;
            }

            return max($highest, (int) $matches[1]);
        }, 0);

        $nextNumber = $highestNumber + 1;
        $padding = max(4, strlen((string) $nextNumber));

        return $prefix.str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT);
    }

    public function import(array $data, array $journeysMap = []): void
    {
        $this->gratitudeImportService->import($data, $journeysMap);
    }

    public function importAccountsData(array $data, array $journeysMap = []): void
    {
        $this->gratitudeImportService->importAccountsData($data, $journeysMap);
    }

    public function importGratitudeTable(array $data): int
    {
        return $this->gratitudeImportService->importGratitudeTable($data);
    }

    public function gratitudeGuests($guests, $gratitude = null): array
    {
        return $this->gratitudeImportService->gratitudeGuests($guests, $gratitude);
    }

    public function allGratitudes(): Collection
    {
        return Gratitude::all();
    }

    public function syncAllAccountBalances(): int
    {
        $synced = 0;

        Gratitude::query()
            ->whereNotNull('gratitudeNumber')
            ->select('id', 'gratitudeNumber')
            ->orderBy('id')
            ->chunkById(100, function ($gratitudes) use (&$synced) {
                foreach ($gratitudes as $gratitude) {
                    self::syncAccountBalance($gratitude->gratitudeNumber);
                    $synced++;
                }
            });

        return $synced;
    }

    public function syncAccountBalancesFor(array $gratitudeNumbers): int
    {
        $numbers = collect($gratitudeNumbers)
            ->filter(fn ($gratitudeNumber) => is_scalar($gratitudeNumber) && trim((string) $gratitudeNumber) !== '')
            ->map(fn ($gratitudeNumber) => (string) $gratitudeNumber)
            ->unique()
            ->values();

        if ($numbers->isEmpty()) {
            return 0;
        }

        $synced = 0;

        foreach ($numbers->chunk(100) as $chunk) {
            Gratitude::query()
                ->whereIn('gratitudeNumber', $chunk->all())
                ->select('id', 'gratitudeNumber')
                ->orderBy('id')
                ->get()
                ->each(function (Gratitude $gratitude) use (&$synced) {
                    self::syncAccountBalance($gratitude->gratitudeNumber);
                    $synced++;
                });
        }

        return $synced;
    }

    private function defaultLevelName(): string
    {
        return GratitudeLevel::where('status', true)
            ->orderBy('min_points')
            ->value('name') ?? 'Explorer';
    }

    private function normalizeAccountStatus(mixed $status): string
    {
        if (is_string($status)) {
            $status = strtolower(trim($status));
        }

        return in_array($status, ['inactive', 'disabled', 'false', false, 0, '0'], true)
            ? 'inactive'
            : 'active';
    }

    private function levelNameAt(Gratitude $gratitude, CarbonInterface $date): string
    {
        $history = collect($gratitude->levelHistory ?? [])
            ->filter(fn ($entry) => is_array($entry) && ! empty($entry['date']))
            ->filter(fn ($entry) => Carbon::parse($entry['date'])->lte($date))
            ->sortBy(fn ($entry) => Carbon::parse($entry['date'])->timestamp)
            ->values();

        if ($history->isNotEmpty()) {
            $entry = $history->last();

            return $entry['toLevel'] ?? $entry['level'] ?? $gratitude->level ?? $this->defaultLevelName();
        }

        return $gratitude->level ?? $this->defaultLevelName();
    }

    /**
     * Redeem points from a gratitude account using soonest-expiring FIFO logic.
     * Journey redemptions use the level redemption rate; partner redemptions use the partner rate.
     */
    public function redeemPoints($gratitude_number, $data, $points)
    {
        try {
            return DB::transaction(function () use ($gratitude_number, $data, $points) {
                $getGratitude = Gratitude::where('gratitudeNumber', $gratitude_number)
                    ->lockForUpdate()
                    ->first();

                if (! $getGratitude) {
                    return false;
                }

                $redemptionType = $data['redemption_type'] ?? $data['category'] ?? 'partner';
                $redemptionDate = ! empty($data['date'])
                    ? Carbon::parse($data['date'])
                    : Carbon::now();
                $levelName = $this->levelNameAt($getGratitude, $redemptionDate);

                if (! empty($data['benefit_key']) && ! (new GratitudeBenefitsService)->levelHasBenefit($levelName, $data['benefit_key'])) {
                    return ['error' => "Your {$levelName} membership does not include the '{$data['benefit_key']}' benefit."];
                }

                $level = GratitudeLevel::where('name', $levelName)->first();
                $pointsPerDollar = $this->pointsPerDollarForRedemption($level, $redemptionType);
                $monetaryValue = round($points / $pointsPerDollar, 2);

                $allPoints = $this->pointLedgerService->redeemableQueue($gratitude_number, $redemptionDate);

                $availableSum = $allPoints->sum(function ($segment) {
                    return (float) $segment->available_points;
                });

                if ($availableSum < $points) {
                    return false;
                }

                $redemption = RedeemPoints::create([
                    'user_id' => $data['user_id'] ?? null,
                    'gratitudeNumber' => $gratitude_number,
                    'points' => $points,
                    'amount' => $data['amount'] ?? $monetaryValue,
                    'reason' => $data['reason'] ?? 'Point Redemption',
                    'category' => $redemptionType,
                    'journey_id' => $data['journey_id'] ?? null,
                    'points_breakdown' => [
                        'redemption_type' => $redemptionType,
                        'level_at_redemption' => $levelName,
                        'points_per_dollar' => $pointsPerDollar,
                        'redemption_date' => $redemptionDate->toDateString(),
                        'usable_points_at_redemption' => $availableSum,
                        'calculated_amount' => $monetaryValue,
                        'journey_data' => $data['journey_data'] ?? null,
                    ],
                    'status' => 'approved',
                ]);
                $redemption->forceFill([
                    'created_at' => $redemptionDate,
                    'updated_at' => $redemptionDate,
                ])->save();

                $pointsRemaining = $points;

                foreach ($allPoints as $segment) {

                    if ($pointsRemaining <= 0) {
                        break;
                    }

                    $available = (float) $segment->available_points;
                    if ($available <= 0) {
                        continue;
                    }

                    $toDeduct = min($available, $pointsRemaining);

                    $segmentMonetaryValue = round($toDeduct / $pointsPerDollar, 2);
                    $existingHistory = is_array($segment->redemption_history) ? $segment->redemption_history : [];
                    $existingHistory[] = [
                        'redemption_id' => $redemption->id,
                        'date' => $redemptionDate->toDateString(),
                        'points' => $toDeduct,
                        'amount' => $segmentMonetaryValue,
                        'reason' => $data['reason'] ?? 'Point Redemption',
                        'redemption_type' => $redemptionType,
                        'level_at_redemption' => $levelName,
                        'points_per_dollar' => $pointsPerDollar,
                        'journey_data' => $segment->project_data ?? null,
                    ];

                    $segment->getConnection()
                        ->table($segment->getTable())
                        ->where('id', $segment->id)
                        ->update([
                            'redeemed_points' => $segment->redeemed_points + $toDeduct,
                            'redemption_history' => json_encode($existingHistory),
                            'updated_at' => Carbon::now(),
                        ]);

                    $segment->redeemed_points += $toDeduct;

                    $this->createRedemptionDetail([
                        'user_id' => $data['user_id'] ?? null,
                        'redeem_id' => $redemption->id,
                        'source_id' => $segment->id,
                        'source_type' => get_class($segment),
                        'points' => $toDeduct,
                        'points_breakdown' => [
                            'date' => $redemptionDate->toDateString(),
                            'level_at_redemption' => $levelName,
                            'points_per_dollar' => $pointsPerDollar,
                            'redemption_type' => $redemptionType,
                        ],
                    ], $redemptionDate);

                    $pointsRemaining -= $toDeduct;
                }

                self::syncAccountBalance($gratitude_number);

                return $redemption;
            });
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function pointsPerDollarForRedemption(?GratitudeLevel $level, ?string $redemptionType): float
    {
        $type = strtolower((string) ($redemptionType ?: 'journey'));

        $rate = $type === 'partner'
            ? ($level?->partner_points_per_dollar ?: $level?->redemption_points_per_dollar)
            : $level?->redemption_points_per_dollar;

        return max(1, (float) ($rate ?: 35));
    }

    protected function compareRedemptionDates(null|CarbonInterface|string $leftDate, null|CarbonInterface|string $rightDate): int
    {
        $leftTimestamp = $leftDate ? Carbon::parse($leftDate)->startOfDay()->timestamp : PHP_INT_MAX;
        $rightTimestamp = $rightDate ? Carbon::parse($rightDate)->startOfDay()->timestamp : PHP_INT_MAX;

        return $leftTimestamp <=> $rightTimestamp;
    }

    protected function getRedemptionEffectiveDate($segment): ?CarbonInterface
    {
        return $segment->usable_date ?? $segment->date ?? $segment->created_at;
    }

    protected function getRedemptionTypePriority($segment): int
    {
        return $segment instanceof BonusPoint ? 0 : 1;
    }

    public static function updateRedemption($id, $data)
    {
        $redemption = RedeemPoints::findOrFail($id);
        $redemption->update([
            'reason' => $data['reason'] ?? $redemption->reason,
            'amount' => $data['amount'] ?? $redemption->amount,
        ]);

        return $redemption;
    }

    public static function deleteRedemption($id)
    {
        $redemption = RedeemPoints::with('details')->findOrFail($id);

        DB::beginTransaction();
        try {
            // Restore points to original sources and remove history entry
            foreach ($redemption->details as $detail) {
                $source = $detail->source; // EarnedPoint or BonusPoint
                if ($source) {
                    $source->redeemed_points = max(0, $source->redeemed_points - $detail->points);
                    if (((int) $source->points - (int) $source->redeemed_points - (int) $source->cancelled_points) > 0) {
                        $source->cancel_id = null;
                    }

                    // Strip this redemption's history entry from the segment
                    $history = $source->redemption_history ?? [];
                    $source->redemption_history = array_values(
                        array_filter($history, fn ($entry) => ($entry['redemption_id'] ?? null) != $id)
                    );

                    $source->save();
                }
                $detail->delete();
            }

            $gratitudeNumber = $redemption->gratitudeNumber;
            $redemption->delete();

            self::syncAccountBalance($gratitudeNumber);

            DB::commit();

            return true;
        } catch (\Exception) {
            DB::rollBack();

            return false;
        }
    }

    private function createRedemptionDetail(array $attributes, CarbonInterface $redemptionDate): RedeemPointsDetails
    {
        $detail = RedeemPointsDetails::create($attributes);

        $detail->forceFill([
            'created_at' => $redemptionDate,
            'updated_at' => $redemptionDate,
        ])->save();

        return $detail;
    }

    public static function syncAccountBalance($gratitudeNumber, bool $recalculateTier = true)
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->first();
        if (! $gratitude) {
            return;
        }

        $now = Carbon::now();

        // Earned totals (uncancelled)
        $totalEarned = (int) EarnedPoint::where('gratitudeNumber', $gratitudeNumber)
            ->sum('points');

        // Bonus totals (uncancelled)
        $totalBonus = (int) BonusPoint::where('gratitudeNumber', $gratitudeNumber)
            ->sum('points');

        // Cancelled points (from cancellations table)
        $totalCancelled = (int) Cancellation::where('gratitudeNumber', $gratitudeNumber)
            ->sum('points');

        // Redeemed points (all approved redemptions)
        $totalRedeemed = (int) RedeemPoints::where('gratitudeNumber', $gratitudeNumber)
            ->sum('points');

        $remainingExpression = 'CASE WHEN COALESCE(points, 0) - COALESCE(redeemed_points, 0) - COALESCE(cancelled_points, 0) > 0 THEN COALESCE(points, 0) - COALESCE(redeemed_points, 0) - COALESCE(cancelled_points, 0) ELSE 0 END';

        // Expired: only the remaining part of each point batch can expire.
        $earnedExpired = (int) EarnedPoint::where('gratitudeNumber', $gratitudeNumber)
            ->whereNull('cancel_id')
            ->where('expires_at', '<=', $now)
            ->sum(DB::raw($remainingExpression));

        $bonusExpired = (int) BonusPoint::where('gratitudeNumber', $gratitudeNumber)
            ->whereNull('cancel_id')
            ->where('expires_at', '<=', $now)
            ->sum(DB::raw($remainingExpression));

        $totalExpired = max(0, $earnedExpired + $bonusExpired);

        // Total lifetime points: earned + bonus
        $totalPoints = $totalEarned + $totalBonus;

        // Useable: uncancelled, active status, unexpired, usable_date passed
        $earnedUseable = (int) EarnedPoint::where('gratitudeNumber', $gratitudeNumber)
            ->whereNull('cancel_id')
            ->activeStatus()
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('usable_date')->orWhere('usable_date', '<=', $now);
            })
            ->sum(DB::raw($remainingExpression));

        $bonusUseable = (int) BonusPoint::where('gratitudeNumber', $gratitudeNumber)
            ->whereNull('cancel_id')
            ->activeStatus()
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('usable_date')->orWhere('usable_date', '<=', $now);
            })
            ->sum(DB::raw($remainingExpression));

        $useablePoints = max(0, $earnedUseable + $bonusUseable);
        $remainingPoints = max(0, $totalPoints - $totalRedeemed - $totalCancelled - $totalExpired);
        $nonUseablePoints = max(0, $totalPoints - $useablePoints);

        $gratitude->totalPoints = max(0, $totalPoints);
        $gratitude->totalEarnedPoints = max(0, $totalEarned);
        $gratitude->totalBonusPoints = max(0, $totalBonus);
        $gratitude->totalExpiredPoints = $totalExpired;
        $gratitude->totalCancelledPoints = max(0, $totalCancelled);
        $gratitude->totalRedeemedPoints = max(0, $totalRedeemed);
        $gratitude->totalRemainingPoints = $remainingPoints;
        $gratitude->useablePoints = $useablePoints;
        $gratitude->nonUseablePoints = $nonUseablePoints;
        $gratitude->last_activity_at = Carbon::now();
        $gratitude->save();

        if ($recalculateTier && $gratitude->gratitudeNumber && $gratitude->systemLevelUpdate) {
            app(TierService::class)->recalculateTier($gratitude->gratitudeNumber);
        }

        return $gratitude->fresh();
    }

    public function gratitudeDataByNumber(string $gratitudeNumber): ?array
    {
        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->first();

        if (! $gratitude) {
            return null;
        }

        $level = GratitudeLevel::where('name', $gratitude->level)->first();

        $earnedPoints = EarnedPoint::where('gratitudeNumber', $gratitudeNumber)
            ->with(['cancellation', 'redemptions.redeemPoint'])
            ->get();

        $bonusPoints = BonusPoint::where('gratitudeNumber', $gratitudeNumber)
            ->with(['cancellation', 'redemptions.redeemPoint'])
            ->get();

        $cancellations = Cancellation::where('gratitudeNumber', $gratitudeNumber)->get();

        $redemptions = RedeemPoints::where('gratitudeNumber', $gratitudeNumber)
            ->with('details')
            ->get();

        return [
            'gratitude' => $gratitude,
            'level_info' => $level,
            'earned_points' => $earnedPoints,
            'bonus_points' => $bonusPoints,
            'cancellations' => $cancellations,
            'redemptions' => $redemptions,
        ];
    }
}
