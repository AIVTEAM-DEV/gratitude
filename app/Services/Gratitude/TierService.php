<?php

namespace App\Services\Gratitude;

use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeLevel;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TierService
{
    public const TIER_EXPLORER = 'Explorer';

    public const TIER_GLOBETROTTER = 'Globetrotter';

    public const TIER_JETSETTER = 'Jetsetter';

    private const EXPIRING_LEVEL_REVIEW_LOOKAHEAD_DAYS = 1;

    private const LEVEL_CYCLE_REVIEW_GRACE_DAYS = 2;

    public function recalculateTier($gratitudeNumber, string $changedBy = 'system', ?CarbonInterface $asOf = null): ?Gratitude
    {
        $asOf = $asOf ? Carbon::parse($asOf) : Carbon::now();

        return DB::transaction(function () use ($gratitudeNumber, $changedBy, $asOf) {
            $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)
                ->lockForUpdate()
                ->first();

            if (! $gratitude) {
                return $gratitude;
            }

            $levels = $this->activeLevels();
            if ($levels->isEmpty()) {
                return $gratitude;
            }

            if (! $gratitude->systemLevelUpdate) {
                if (! $this->manualOverrideCycleExpired($gratitude, $levels, $asOf)) {
                    return $gratitude;
                }

                $gratitude->forceFill(['systemLevelUpdate' => true])->save();
                $gratitude->refresh();
            }

            $this->ensureInitialLevel($gratitude, $levels, $asOf, $changedBy);
            $gratitude->refresh();

            $iterations = 0;
            while ($iterations < 20) {
                [$cycleStart, $cycleEnd] = $this->cycleWindow($gratitude, $levels, $asOf);
                $reviewEnd = $asOf->lt($cycleEnd) ? $asOf->copy() : $cycleEnd->copy();
                $oldLevel = $gratitude->level ?? $this->defaultLevelName($levels);
                $expiringSoonReview = $this->expiringSoonLevelReview($gratitude->gratitudeNumber, $cycleStart, $asOf);

                if ($expiringSoonReview !== null) {
                    $metrics = $expiringSoonReview['metrics'];
                    $targetLevel = $this->resolveLevelForMetrics($metrics['earned_points'], $metrics['journey_count'], $levels);

                    if ($targetLevel !== $oldLevel) {
                        $this->applyLevelChange(
                            $gratitude,
                            $oldLevel,
                            $targetLevel,
                            $metrics,
                            $changedBy,
                            $asOf->copy(),
                            $asOf->copy(),
                            'expiry_review'
                        );

                        $gratitude->refresh();
                    }

                    break;
                }

                $metrics = $this->cycleMetrics($gratitude->gratitudeNumber, $cycleStart, $reviewEnd);
                $targetLevel = $this->resolveLevelForMetrics($metrics['earned_points'], $metrics['journey_count'], $levels);
                $newLevel = $this->nextHigherLevel($oldLevel, $targetLevel, $levels);

                if ($newLevel !== null) {
                    $qualification = $this->qualificationSnapshot($gratitude->gratitudeNumber, $cycleStart, $reviewEnd, $newLevel, $levels);
                    $effectiveAt = $qualification['date'] ?? $reviewEnd;

                    $this->applyLevelChange(
                        $gratitude,
                        $oldLevel,
                        $newLevel,
                        $qualification['metrics'] ?? $metrics,
                        $changedBy,
                        $effectiveAt,
                        $effectiveAt,
                        'threshold_upgrade'
                    );

                    $gratitude->refresh();
                    $iterations++;

                    continue;
                }

                if ($asOf->lt($cycleEnd)) {
                    break;
                }

                $this->applyLevelChange(
                    $gratitude,
                    $oldLevel,
                    $targetLevel,
                    $metrics,
                    $changedBy,
                    $cycleEnd,
                    $cycleEnd,
                    'cycle_review'
                );

                $gratitude->refresh();
                $iterations++;
            }

            return $gratitude->fresh();
        });
    }

    public function recalculateDueCycles(?CarbonInterface $asOf = null): int
    {
        $asOf = $asOf ? Carbon::parse($asOf) : Carbon::now();
        $count = 0;

        Gratitude::query()
            ->whereNotNull('gratitudeNumber')
            ->orderBy('id')
            ->chunkById(100, function ($gratitudes) use (&$count, $asOf) {
                foreach ($gratitudes as $gratitude) {
                    $this->recalculateTier($gratitude->gratitudeNumber, 'scheduled_cycle_check', $asOf);
                    $count++;
                }
            });

        return $count;
    }

    public function setLevelManually(Gratitude $gratitude, string $newLevel, string $changedBy, ?string $reason = null): Gratitude
    {
        return DB::transaction(function () use ($gratitude, $newLevel, $changedBy, $reason) {
            $gratitude = Gratitude::whereKey($gratitude->id)->lockForUpdate()->firstOrFail();
            $oldLevel = $gratitude->level ?? self::TIER_EXPLORER;
            $now = Carbon::now();
            $metrics = $this->cycleMetrics(
                $gratitude->gratitudeNumber,
                $gratitude->level_obtained_at ? Carbon::parse($gratitude->level_obtained_at) : $now,
                $now
            );

            $history = $this->appendLevelHistory(
                $gratitude->levelHistory ?? [],
                $oldLevel,
                $newLevel,
                $now,
                $metrics,
                $changedBy,
                $this->determineStatusChange($oldLevel, $newLevel),
                $reason ?? 'Manual override'
            );

            $gratitude->update([
                'level' => $newLevel,
                'levelHistory' => $history,
                'level_obtained_at' => $now,
                'statusChange' => $this->determineStatusChange($oldLevel, $newLevel),
                'statusChangeReason' => $reason ?? 'Manual override',
                'systemLevelUpdate' => false,
            ]);

            return $gratitude->fresh();
        });
    }

    public function enableAutoLevelUpdate(Gratitude $gratitude): Gratitude
    {
        $gratitude->update(['systemLevelUpdate' => true]);

        return $gratitude->fresh();
    }

    public function checkInactivity($gratitudeNumber): bool
    {
        $twoYearsAgo = Carbon::today()->subYears(2);

        $recentJourneyCount = EarnedPoint::where('gratitudeNumber', $gratitudeNumber)
            ->activeStatus()
            ->whereNull('cancel_id')
            ->whereNotNull('journey_id')
            ->whereNotNull('usable_date')
            ->where('usable_date', '>=', $twoYearsAgo)
            ->distinct('journey_id')
            ->count('journey_id');

        if ($recentJourneyCount > 0) {
            return false;
        }

        $bonusBalance = BonusPoint::where('gratitudeNumber', $gratitudeNumber)
            ->activeStatus()
            ->withRemainingPoints()
            ->sum(BonusPoint::raw('CASE WHEN COALESCE(points, 0) - COALESCE(redeemed_points, 0) - COALESCE(cancelled_points, 0) > 0 THEN COALESCE(points, 0) - COALESCE(redeemed_points, 0) - COALESCE(cancelled_points, 0) ELSE 0 END'));

        if ($bonusBalance <= 0) {
            $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->first();
            if ($gratitude) {
                $gratitude->update(['status' => 'inactive', 'is_active' => false]);
            }

            return true;
        }

        return false;
    }

    private function activeLevels(): Collection
    {
        return GratitudeLevel::where('status', true)
            ->orderBy('min_points')
            ->get();
    }

    private function manualOverrideCycleExpired(Gratitude $gratitude, Collection $levels, CarbonInterface $asOf): bool
    {
        $currentLevel = $levels->firstWhere('name', $gratitude->level) ?? $levels->first();
        $years = max(1, (int) ($currentLevel?->level_interval_years ?: 2));
        $start = $gratitude->level_obtained_at
            ? Carbon::parse($gratitude->level_obtained_at)
            : ($gratitude->updated_at ? Carbon::parse($gratitude->updated_at) : Carbon::now());

        return Carbon::parse($asOf)->gte($this->configuredCycleEnd($start, $years));
    }

    private function ensureInitialLevel(Gratitude $gratitude, Collection $levels, Carbon $asOf, string $changedBy): void
    {
        if ($gratitude->level && ! empty($gratitude->levelHistory)) {
            return;
        }

        $level = $this->defaultLevelName($levels);
        $start = $this->cycleStart($gratitude, $asOf);
        $metrics = $this->cycleMetrics($gratitude->gratitudeNumber, $start, $start);

        $gratitude->update([
            'level' => $level,
            'levelHistory' => [[
                'fromLevel' => $level,
                'toLevel' => $level,
                'changeType' => 'initial',
                'date' => $start->toDateString(),
                'earnedPoints' => (int) $metrics['earned_points'],
                'journeyCount' => (int) $metrics['journey_count'],
                'changedBy' => $changedBy,
                'reason' => 'Initial Gratitude level assigned',
            ]],
            'level_obtained_at' => $start,
            'statusChange' => 'initial',
            'statusChangeReason' => 'Initial Gratitude level assigned',
        ]);
    }

    private function cycleWindow(Gratitude $gratitude, Collection $levels, Carbon $asOf): array
    {
        $start = $this->cycleStart($gratitude, $asOf);
        $currentLevel = $levels->firstWhere('name', $gratitude->level) ?? $levels->first();
        $years = max(1, (int) ($currentLevel?->level_interval_years ?: 2));

        return [$start, $this->configuredCycleEnd($start, $years)];
    }

    private function configuredCycleEnd(CarbonInterface $start, int $years): Carbon
    {
        return Carbon::parse($start)
            ->copy()
            ->addYears(max(1, $years))
            ->addDays(self::LEVEL_CYCLE_REVIEW_GRACE_DAYS);
    }

    private function cycleStart(Gratitude $gratitude, Carbon $asOf): Carbon
    {
        if ($gratitude->level_obtained_at) {
            return Carbon::parse($gratitude->level_obtained_at);
        }

        $earliestEarnedDate = EarnedPoint::where('gratitudeNumber', $gratitude->gratitudeNumber)
            ->whereNotNull('usable_date')
            ->min('usable_date');

        if ($earliestEarnedDate) {
            return Carbon::parse($earliestEarnedDate);
        }

        return $gratitude->created_at ? Carbon::parse($gratitude->created_at) : $asOf->copy();
    }

    private function cycleMetrics(string $gratitudeNumber, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->remainingCycleMetrics($gratitudeNumber, $from, $to);
    }

    private function expiringSoonLevelReview(string $gratitudeNumber, CarbonInterface $from, Carbon $asOf): ?array
    {
        $windowStart = $asOf->copy()->startOfDay();
        $reviewCutoff = $asOf->copy()
            ->addDays(self::EXPIRING_LEVEL_REVIEW_LOOKAHEAD_DAYS)
            ->endOfDay();

        if (! $this->hasEarnedPointsExpiringForLevelReview($gratitudeNumber, $windowStart, $reviewCutoff, $asOf)) {
            return null;
        }

        return [
            'metrics' => $this->remainingCycleMetrics($gratitudeNumber, $from, $reviewCutoff),
            'review_cutoff' => $reviewCutoff,
        ];
    }

    private function hasEarnedPointsExpiringForLevelReview(
        string $gratitudeNumber,
        CarbonInterface $from,
        CarbonInterface $to,
        CarbonInterface $asOf
    ): bool {
        return EarnedPoint::where('gratitudeNumber', $gratitudeNumber)
            ->whereNull('cancel_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>=', $from)
            ->where('expires_at', '<=', $to)
            ->whereRaw($this->remainingPointsExpression())
            ->where(function ($query) use ($asOf) {
                $query->activeStatus()
                    ->orWhere('expires_at', '<', $asOf);
            })
            ->exists();
    }

    private function remainingCycleMetrics(string $gratitudeNumber, CarbonInterface $from, CarbonInterface $to): array
    {
        $query = $this->remainingEarnedPointsForLevelQuery($gratitudeNumber, $from, $to);

        $earnedPoints = (int) $query
            ->sum(DB::raw('CASE WHEN '.$this->remainingPointsExpression().' THEN COALESCE(points, 0) - COALESCE(redeemed_points, 0) - COALESCE(cancelled_points, 0) ELSE 0 END'));

        $journeyCount = (int) $this->remainingEarnedPointsForLevelQuery($gratitudeNumber, $from, $to)
            ->withRemainingPoints()
            ->whereNotNull('journey_id')
            ->distinct('journey_id')
            ->count('journey_id');

        return [
            'earned_points' => $earnedPoints,
            'journey_count' => $journeyCount,
        ];
    }

    private function remainingEarnedPointsForLevelQuery(string $gratitudeNumber, CarbonInterface $from, CarbonInterface $to)
    {
        return EarnedPoint::where('gratitudeNumber', $gratitudeNumber)
            ->activeStatus()
            ->whereNull('cancel_id')
            ->whereNotNull('usable_date')
            ->where('usable_date', '>=', $from)
            ->where('usable_date', '<=', $to)
            ->where(function ($query) use ($to) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $to);
            });
    }

    private function remainingPointsExpression(): string
    {
        return 'COALESCE(points, 0) > COALESCE(redeemed_points, 0) + COALESCE(cancelled_points, 0)';
    }

    private function qualificationSnapshot(string $gratitudeNumber, CarbonInterface $from, CarbonInterface $to, string $targetLevel, Collection $levels): array
    {
        $earnedPoints = 0;
        $journeys = [];
        $targetRank = $this->rank($targetLevel, $levels);

        $points = $this->remainingEarnedPointsForLevelQuery($gratitudeNumber, $from, $to)
            ->withRemainingPoints()
            ->orderBy('usable_date')
            ->orderBy('id')
            ->get();

        foreach ($points as $point) {
            $earnedPoints += max(
                0,
                (int) $point->points - (int) $point->redeemed_points - (int) $point->cancelled_points
            );

            if ($point->journey_id) {
                $journeys[(string) $point->journey_id] = true;
            }

            $resolvedLevel = $this->resolveLevelForMetrics($earnedPoints, count($journeys), $levels);

            if ($this->rank($resolvedLevel, $levels) >= $targetRank) {
                return [
                    'date' => Carbon::parse($point->usable_date),
                    'metrics' => [
                        'earned_points' => $earnedPoints,
                        'journey_count' => count($journeys),
                    ],
                ];
            }
        }

        return [];
    }

    private function resolveLevelForMetrics(int $earnedPoints, int $journeyCount, Collection $levels): string
    {
        foreach ($levels->sortByDesc('min_points') as $level) {
            if ($earnedPoints < (int) $level->min_points) {
                continue;
            }

            if ($journeyCount < $this->minimumJourneysFor($level)) {
                continue;
            }

            return $level->name;
        }

        return $this->defaultLevelName($levels);
    }

    private function minimumJourneysFor(GratitudeLevel $level): int
    {
        return max(0, (int) ($level->min_journeys ?? $level->jetsetter_min_journeys ?? 0));
    }

    private function nextHigherLevel(string $currentLevel, string $targetLevel, Collection $levels): ?string
    {
        $currentRank = $this->rank($currentLevel, $levels);
        $targetRank = $this->rank($targetLevel, $levels);

        if ($targetRank <= $currentRank) {
            return null;
        }

        return $levels
            ->sortBy('min_points')
            ->values()
            ->first(fn ($level, int $index) => $index + 1 === $currentRank + 1)
            ?->name;
    }

    private function defaultLevelName(Collection $levels): string
    {
        return $levels->sortBy('min_points')->first()?->name ?? self::TIER_EXPLORER;
    }

    private function applyLevelChange(
        Gratitude $gratitude,
        string $oldLevel,
        string $newLevel,
        array $metrics,
        string $changedBy,
        Carbon $now,
        Carbon $levelObtainedAt,
        string $context
    ): void {
        $changeType = $this->determineStatusChange($oldLevel, $newLevel);

        $history = $this->appendLevelHistory(
            $gratitude->levelHistory ?? [],
            $oldLevel,
            $newLevel,
            $now,
            $metrics,
            $changedBy,
            $changeType,
            $this->buildChangeReason($changeType, $oldLevel, $newLevel, $metrics, $context)
        );

        $gratitude->update([
            'level' => $newLevel,
            'levelHistory' => $history,
            'level_obtained_at' => $levelObtainedAt,
            'statusChange' => $changeType,
            'statusChangeReason' => $this->buildChangeReason($changeType, $oldLevel, $newLevel, $metrics, $context),
        ]);
    }

    private function appendLevelHistory(
        array $history,
        string $fromLevel,
        string $toLevel,
        Carbon $date,
        array $metrics,
        string $changedBy,
        ?string $changeType,
        ?string $reason = null
    ): array {
        $entry = [
            'fromLevel' => $fromLevel,
            'toLevel' => $toLevel,
            'changeType' => $changeType ?? 'maintained',
            'date' => $date->toDateString(),
            'earnedPoints' => (int) ($metrics['earned_points'] ?? 0),
            'journeyCount' => (int) ($metrics['journey_count'] ?? 0),
            'changedBy' => $changedBy,
            'reason' => $reason ?? $this->buildChangeReason($changeType, $fromLevel, $toLevel, $metrics, 'cycle_review'),
        ];

        foreach ($history as $index => $existingEntry) {
            if (! is_array($existingEntry)) {
                continue;
            }

            $existingDate = ! empty($existingEntry['date'])
                ? Carbon::parse($existingEntry['date'])->toDateString()
                : null;
            $existingToLevel = $existingEntry['toLevel'] ?? $existingEntry['level'] ?? null;
            $existingChangeType = $existingEntry['changeType'] ?? null;

            if (
                $existingDate === $entry['date']
                && $existingToLevel === $entry['toLevel']
                && $existingChangeType === $entry['changeType']
            ) {
                $history[$index] = array_merge($existingEntry, $entry);

                return array_values($history);
            }
        }

        $history[] = $entry;

        return array_values($history);
    }

    private function determineStatusChange(string $oldLevel, string $newLevel): ?string
    {
        if ($oldLevel === $newLevel) {
            return 'maintained';
        }

        $levels = $this->activeLevels();

        return $this->rank($newLevel, $levels) > $this->rank($oldLevel, $levels)
            ? 'upgrade'
            : 'downgrade';
    }

    private function rank(string $levelName, Collection $levels): int
    {
        $rank = 1;

        foreach ($levels->sortBy('min_points')->values() as $index => $level) {
            if ($level->name === $levelName) {
                $rank = $index + 1;
                break;
            }
        }

        return $rank;
    }

    private function buildChangeReason(?string $changeType, string $fromLevel, string $toLevel, array $metrics, string $context): string
    {
        $points = number_format((int) ($metrics['earned_points'] ?? 0));
        $journeys = (int) ($metrics['journey_count'] ?? 0);

        if ($context === 'expiry_review') {
            return match ($changeType) {
                'upgrade' => "Upgraded from {$fromLevel} to {$toLevel}: {$points} remaining eligible earned points and {$journeys} journeys after points expiring by tomorrow.",
                'downgrade' => "Downgraded from {$fromLevel} to {$toLevel}: {$points} remaining eligible earned points and {$journeys} journeys after points expiring by tomorrow.",
                'maintained' => "Maintained {$toLevel}: {$points} remaining eligible earned points and {$journeys} journeys after points expiring by tomorrow.",
                default => "Level set to {$toLevel}.",
            };
        }

        return match ($changeType) {
            'upgrade' => "Upgraded from {$fromLevel} to {$toLevel}: {$points} eligible earned points and {$journeys} journeys in the cycle.",
            'downgrade' => "Downgraded from {$fromLevel} to {$toLevel}: {$points} eligible earned points and {$journeys} journeys in the reviewed cycle.",
            'maintained' => $context === 'cycle_review'
                ? "Maintained {$toLevel}: {$points} eligible earned points and {$journeys} journeys in the reviewed cycle."
                : "Level {$toLevel} retained.",
            default => "Level set to {$toLevel}.",
        };
    }
}
