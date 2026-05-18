<?php

namespace App\Services\Gratitude;

use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\EarnedPoint;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PointLedgerService
{
    public function redeemableQueue(string $gratitudeNumber, ?Carbon $asOf = null, bool $lockForUpdate = true): Collection
    {
        $asOf ??= Carbon::now();

        $earnedQuery = EarnedPoint::query()->redeemable($gratitudeNumber, $asOf);
        $bonusQuery = BonusPoint::query()->redeemable($gratitudeNumber, $asOf);

        if ($lockForUpdate) {
            $earnedQuery->lockForUpdate();
            $bonusQuery->lockForUpdate();
        }

        return $earnedQuery->get()
            ->concat($bonusQuery->get())
            ->map(fn (Model $point) => $this->withAvailablePoints($point))
            ->filter(fn (Model $point) => (int) $point->available_points > 0)
            ->sort(fn (Model $left, Model $right) => $this->compareForRedemption($left, $right))
            ->values();
    }

    public function remainingPoints(Model $point): int
    {
        return max(
            0,
            (int) $point->points - (int) $point->redeemed_points - (int) $point->cancelled_points
        );
    }

    private function withAvailablePoints(Model $point): Model
    {
        $point->available_points = $this->remainingPoints($point);
        $point->type = $point instanceof BonusPoint ? 'bonus' : 'earned';

        return $point;
    }

    private function compareForRedemption(Model $left, Model $right): int
    {
        $leftExpiry = $left->expires_at ? Carbon::parse($left->expires_at)->timestamp : PHP_INT_MAX;
        $rightExpiry = $right->expires_at ? Carbon::parse($right->expires_at)->timestamp : PHP_INT_MAX;

        if ($leftExpiry !== $rightExpiry) {
            return $leftExpiry <=> $rightExpiry;
        }

        $leftUsableDate = Carbon::parse($left->usable_date ?? $left->date ?? $left->created_at)->timestamp;
        $rightUsableDate = Carbon::parse($right->usable_date ?? $right->date ?? $right->created_at)->timestamp;

        if ($leftUsableDate !== $rightUsableDate) {
            return $leftUsableDate <=> $rightUsableDate;
        }

        $leftType = $left instanceof BonusPoint ? 2 : 1;
        $rightType = $right instanceof BonusPoint ? 2 : 1;

        if ($leftType !== $rightType) {
            return $leftType <=> $rightType;
        }

        return ((int) $left->id) <=> ((int) $right->id);
    }
}
