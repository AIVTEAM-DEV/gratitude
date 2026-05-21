<?php

namespace App\Services\Gratitude;

use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\Cancellation;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancellationService
{
    public function __construct(protected PointLedgerService $pointLedgerService) {}

    public function cancel(Gratitude $gratitude, array $data, ?int $earnedPointId = null, ?int $bonusPointId = null): Cancellation
    {
        return DB::transaction(function () use ($gratitude, $data, $earnedPointId, $bonusPointId) {
            $pointsToCancel = (int) $data['cancellation_points'];

            $cancel = Cancellation::create([
                'user_id' => $gratitude->user_id,
                'gratitudeNumber' => $gratitude->gratitudeNumber,
                'date' => $data['date'],
                'description' => $data['cancellation_reason'],
                'points' => $pointsToCancel,
                'status' => 'approved',
            ]);

            if ($earnedPointId && $bonusPointId) {
                throw ValidationException::withMessages([
                    'earned_point_id' => 'Cancel one point source at a time.',
                ]);
            }

            if ($earnedPointId || $bonusPointId) {
                $source = $earnedPointId
                    ? EarnedPoint::where('gratitudeNumber', $gratitude->gratitudeNumber)->lockForUpdate()->findOrFail($earnedPointId)
                    : BonusPoint::where('gratitudeNumber', $gratitude->gratitudeNumber)->lockForUpdate()->findOrFail($bonusPointId);

                $available = $this->pointLedgerService->remainingPoints($source);

                if ($pointsToCancel > $available) {
                    throw ValidationException::withMessages([
                        'cancellation_points' => "Only {$available} points remain available to cancel for this entry.",
                    ]);
                }

                $allocations = [$this->applyCancellationToSource($source, $pointsToCancel, $cancel)];
            } else {
                $allocations = $this->cancelFromQueue($gratitude->gratitudeNumber, $pointsToCancel, $cancel);
            }

            $cancel->update(['points_breakdown' => $allocations]);

            GratitudeService::syncAccountBalance($gratitude->gratitudeNumber);

            return $cancel->fresh();
        });
    }

    public function expire(Gratitude $gratitude, array $data): Cancellation
    {
        return $this->cancel($gratitude, [
            'date' => $data['date'],
            'cancellation_reason' => 'Manual points expiration',
            'cancellation_points' => $data['points'],
        ]);
    }

    public function delete(Cancellation $cancel): void
    {
        $gratitudeNumber = $cancel->gratitudeNumber;

        DB::transaction(function () use ($cancel) {
            $allocations = $cancel->points_breakdown ?? [];

            if ($allocations) {
                foreach ($allocations as $allocation) {
                    $source = $this->findSource($allocation['source_type'] ?? null, $allocation['source_id'] ?? null);
                    if (! $source) {
                        continue;
                    }

                    $source->cancelled_points = max(0, (int) $source->cancelled_points - (int) ($allocation['points'] ?? 0));
                    if ((int) $source->cancel_id === (int) $cancel->id) {
                        $source->cancel_id = null;
                    }
                    $source->save();
                }
            } else {
                EarnedPoint::where('cancel_id', $cancel->id)->update(['cancel_id' => null, 'cancelled_points' => 0]);
                BonusPoint::where('cancel_id', $cancel->id)->update(['cancel_id' => null, 'cancelled_points' => 0]);
            }

            $cancel->delete();
        });

        GratitudeService::syncAccountBalance($gratitudeNumber);
    }

    public function removeForSource(Model $source): void
    {
        DB::transaction(function () use ($source) {
            $sourceType = get_class($source);
            $sourceId = $source->getKey();

            Cancellation::where('gratitudeNumber', $source->gratitudeNumber)
                ->get()
                ->each(function (Cancellation $cancel) use ($source, $sourceType, $sourceId) {
                    $allocations = $cancel->points_breakdown ?? [];

                    if ($allocations === []) {
                        if ((int) $source->cancel_id === (int) $cancel->id) {
                            $cancel->delete();
                        }

                        return;
                    }

                    $remainingAllocations = [];
                    $removed = false;

                    foreach ($allocations as $allocation) {
                        $matchesSource = ($allocation['source_type'] ?? null) === $sourceType
                            && (int) ($allocation['source_id'] ?? 0) === (int) $sourceId;

                        if ($matchesSource) {
                            $removed = true;

                            continue;
                        }

                        $remainingAllocations[] = $allocation;
                    }

                    if (! $removed) {
                        return;
                    }

                    if ($remainingAllocations === []) {
                        $cancel->delete();

                        return;
                    }

                    $cancel->update([
                        'points' => collect($remainingAllocations)->sum(fn ($allocation) => (int) ($allocation['points'] ?? 0)),
                        'points_breakdown' => array_values($remainingAllocations),
                    ]);
                });

            $source->forceFill([
                'cancel_id' => null,
                'cancelled_points' => 0,
            ])->save();
        });
    }

    private function cancelFromQueue(string $gratitudeNumber, int $pointsToCancel, Cancellation $cancel): array
    {
        $remaining = $pointsToCancel;
        $allocations = [];

        foreach ($this->pointLedgerService->redeemableQueue($gratitudeNumber) as $source) {
            if ($remaining <= 0) {
                break;
            }

            $available = $this->pointLedgerService->remainingPoints($source);
            if ($available <= 0) {
                continue;
            }

            $points = min($available, $remaining);
            $allocations[] = $this->applyCancellationToSource($source, $points, $cancel);
            $remaining -= $points;
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'cancellation_points' => 'Only '.($pointsToCancel - $remaining).' points are available to cancel.',
            ]);
        }

        return $allocations;
    }

    private function applyCancellationToSource(Model $source, int $points, Cancellation $cancel): array
    {
        $source->cancelled_points = (int) $source->cancelled_points + $points;

        if ($this->pointLedgerService->remainingPoints($source) <= 0) {
            $source->cancel_id = $cancel->id;
        }

        $source->save();

        return [
            'source_type' => get_class($source),
            'source_id' => $source->id,
            'points' => $points,
            'remaining_after' => $this->pointLedgerService->remainingPoints($source),
        ];
    }

    private function findSource(?string $sourceType, mixed $sourceId): ?Model
    {
        if (! $sourceType || ! $sourceId || ! in_array($sourceType, [EarnedPoint::class, BonusPoint::class], true)) {
            return null;
        }

        return $sourceType::query()->find($sourceId);
    }
}
