<?php

namespace App\Services\Gratitude;

use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\Cancellation;
use App\Models\Gratitude\Gratitude;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BonusPointService
{
    public function __construct(
        protected PointExpiryService $pointExpiryService,
        protected CancellationService $cancellationService,
    ) {}

    public function add(Gratitude $gratitude, array $data): BonusPoint
    {
        $bonusType = $data['type'] ?? 'other';
        $effectiveDate = $this->effectiveDate($data, $bonusType);
        $level = $this->pointExpiryService->resolveLevelForGratitude($gratitude);

        $point = BonusPoint::create([
            'user_id' => $gratitude->user_id,
            'gratitudeNumber' => $gratitude->gratitudeNumber,
            'date' => $effectiveDate,
            'category' => $data['category'] ?? $bonusType,
            'type' => $bonusType,
            'journey_id' => $data['journey_id'] ?? null,
            'amount' => $data['amount'] ?? null,
            'description' => $data['description'],
            'points' => $data['points'],
            'points_breakdown' => $this->pointsBreakdown($data, $effectiveDate, $bonusType),
            'usable_date' => $effectiveDate,
            'expires_at' => $this->pointExpiryService->calculateBonusExpiry($effectiveDate, $level),
            'status' => true,
        ]);

        GratitudeService::syncAccountBalance($gratitude->gratitudeNumber);

        return $point;
    }

    public function update(BonusPoint $point, Gratitude $gratitude, array $data): BonusPoint
    {
        $updated = DB::transaction(function () use ($point, $gratitude, $data) {
            $this->cancellationService->removeForSource($point);
            $point->refresh();

            $bonusType = $data['type'] ?? $point->type ?? 'other';
            $effectiveDate = $this->effectiveDate($data, $bonusType);
            $level = $this->pointExpiryService->resolveLevelForGratitude($gratitude);

            if (! empty($data['expires_at'])) {
                $expiresAt = Carbon::parse($data['expires_at']);
                $isManual = true;
            } elseif ($point->expires_at_manual) {
                $expiresAt = $point->expires_at;
                $isManual = true;
            } else {
                $expiresAt = $this->pointExpiryService->calculateBonusExpiry($effectiveDate, $level);
                $isManual = false;
            }

            $point->update([
                'date' => $effectiveDate,
                'category' => $data['category'] ?? $point->category,
                'type' => $bonusType,
                'journey_id' => $data['journey_id'] ?? $point->journey_id,
                'amount' => $data['amount'] ?? $point->amount,
                'description' => $data['description'],
                'points' => $data['points'],
                'points_breakdown' => $this->pointsBreakdown($data, $effectiveDate, $bonusType),
                'usable_date' => $effectiveDate,
                'expires_at' => $expiresAt,
                'expires_at_manual' => $isManual,
                'status' => true,
            ]);

            return $point->fresh();
        });

        GratitudeService::syncAccountBalance($gratitude->gratitudeNumber);

        return $updated;
    }

    public function delete(BonusPoint $point): void
    {
        $gratitudeNumber = $point->gratitudeNumber;

        if ($point->cancel_id) {
            Cancellation::where('id', $point->cancel_id)->delete();
        }

        $point->delete();

        GratitudeService::syncAccountBalance($gratitudeNumber);
    }

    private function effectiveDate(array $data, string $bonusType): Carbon
    {
        $date = $bonusType === 'referring_guest'
            ? ($data['journey_end_date'] ?? $this->journeyEndDate($data['journey_data'] ?? null) ?? $data['date'] ?? null)
            : ($data['date'] ?? null);

        return Carbon::parse($date);
    }

    private function pointsBreakdown(array $data, Carbon $effectiveDate, string $bonusType): array
    {
        return [
            'bonus_type' => $bonusType,
            'guest_id' => $data['guest_id'] ?? null,
            'guest_name' => $data['guest_name'] ?? null,
            'journey_id' => $data['journey_id'] ?? null,
            'journey_end_date' => $data['journey_end_date'] ?? null,
            'journey_data' => $data['journey_data'] ?? null,
            'entry_date' => $effectiveDate->toDateString(),
        ];
    }

    private function journeyEndDate(mixed $journeyData): mixed
    {
        if (! is_array($journeyData)) {
            return null;
        }

        foreach (['endDate', 'end_date', 'returnDate', 'return_date', 'date_end'] as $key) {
            if (! empty($journeyData[$key])) {
                return $journeyData[$key];
            }
        }

        return null;
    }
}
