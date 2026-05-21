<?php

namespace App\Services\Gratitude;

use App\Models\Gratitude\Cancellation;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EarnedPointService
{
    public function __construct(
        protected PointExpiryService $pointExpiryService,
        protected CancellationService $cancellationService,
    ) {}

    public function add(Gratitude $gratitude, array $data): EarnedPoint
    {
        $earningType = $this->earningType($data);
        $usableDate = $this->effectiveDate($data, $earningType);
        $level = $this->pointExpiryService->resolveLevelForGratitude($gratitude);

        $point = EarnedPoint::create([
            'user_id' => $gratitude->user_id,
            'gratitudeNumber' => $gratitude->gratitudeNumber,
            'date' => $usableDate,
            'category' => $data['category'],
            'points' => $data['points'],
            'amount' => $data['amount'],
            'description' => $data['description'],
            'journey_id' => $data['journey_id'] ?? null,
            'points_breakdown' => $this->pointsBreakdown($data, $usableDate),
            'status' => true,
            'usable_date' => $usableDate,
            'expires_at' => $this->pointExpiryService->calculateEarnedExpiry($usableDate, $level),
            'project_data' => $earningType === 'journey' ? ($data['project_data'] ?? null) : null,
        ]);

        GratitudeService::syncAccountBalance($gratitude->gratitudeNumber);

        return $point;
    }

    public function update(EarnedPoint $point, Gratitude $gratitude, array $data): EarnedPoint
    {
        $updated = DB::transaction(function () use ($point, $gratitude, $data) {
            $this->cancellationService->removeForSource($point);
            $point->refresh();

            $earningType = $this->earningType($data);
            $usableDate = $this->effectiveDate($data, $earningType);
            $level = $this->pointExpiryService->resolveLevelForGratitude($gratitude);

            // Determine expiry: if manually provided → use it.
            // If already manually set and not provided → keep existing.
            // Otherwise → recalculate from level.
            if (! empty($data['expires_at'])) {
                $expiresAt = Carbon::parse($data['expires_at']);
                $isManual = true;
            } elseif ($point->expires_at_manual) {
                $expiresAt = $point->expires_at;
                $isManual = true;
            } else {
                $expiresAt = $this->pointExpiryService->calculateEarnedExpiry($usableDate, $level);
                $isManual = false;
            }

            $point->update([
                'date' => $usableDate,
                'category' => $data['category'],
                'points' => $data['points'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'journey_id' => $data['journey_id'] ?? null,
                'points_breakdown' => $this->pointsBreakdown($data, $usableDate),
                'usable_date' => $usableDate,
                'expires_at' => $expiresAt,
                'expires_at_manual' => $isManual,
                'status' => true,
                'project_data' => $earningType === 'journey' ? ($data['project_data'] ?? $point->project_data) : null,
            ]);

            return $point->fresh();
        });

        GratitudeService::syncAccountBalance($gratitude->gratitudeNumber);

        return $updated;
    }

    public function delete(EarnedPoint $point): void
    {
        $gratitudeNumber = $point->gratitudeNumber;

        if ($point->cancel_id) {
            Cancellation::where('id', $point->cancel_id)->delete();
        }

        $point->delete();

        GratitudeService::syncAccountBalance($gratitudeNumber);
    }

    private function pointsBreakdown(array $data, Carbon $date): array
    {
        $amount = (float) ($data['amount'] ?? 0);
        $points = (int) ($data['points'] ?? 0);
        $earningType = $this->earningType($data);

        return [
            'points' => $points,
            'amount' => $amount,
            'points_per_dollar' => $amount > 0 ? round($points / $amount, 4) : null,
            'journey_id' => $data['journey_id'] ?? null,
            'entry_date' => $date->toDateString(),
            'earning_type' => $earningType,
            'journey_end_date' => $data['journey_end_date'] ?? null,
            'project_data' => $data['project_data'] ?? null,
        ];
    }

    private function earningType(array $data): string
    {
        return $data['earning_type'] ?? (! empty($data['journey_id']) ? 'journey' : 'other');
    }

    private function effectiveDate(array $data, string $earningType): Carbon
    {
        $date = $earningType === 'journey'
            ? ($data['journey_end_date'] ?? $this->projectEndDate($data['project_data'] ?? null) ?? $data['date'] ?? null)
            : ($data['date'] ?? null);

        return Carbon::parse($date);
    }

    private function projectEndDate(mixed $projectData): mixed
    {
        if (! is_array($projectData)) {
            return null;
        }

        foreach (['endDate', 'end_date', 'returnDate', 'return_date', 'date_end'] as $key) {
            if (! empty($projectData[$key])) {
                return $projectData[$key];
            }
        }

        return null;
    }
}
