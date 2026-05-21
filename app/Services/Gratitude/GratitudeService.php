<?php

namespace App\Services\Gratitude;

use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\Cancellation;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeLevel;
use App\Models\Gratitude\RedeemPoints;
use App\Models\Gratitude\RedeemPointsDetails;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GratitudeService
{
    public function __construct(
        protected PointExpiryService $pointExpiryService,
        protected PointLedgerService $pointLedgerService,
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
            if (! $gratitudeNumber || ! preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $gratitudeNumber, $matches)) {
                return $highest;
            }

            return max($highest, (int) $matches[1]);
        }, 0);

        $nextNumber = $highestNumber + 1;
        $padding = max(4, strlen((string) $nextNumber));

        return $prefix . str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT);
    }

    public function import(array $data, array $journeysMap = []): void
    {
        foreach ($data as $record) {
            $hasPointData = $this->hasImportedPointDatasets($record);
            $guestsData = Gratitude::extractGuestsData($record);

            $gratitudeValues = [
                'gratitudeNumber' => $record['gratitudeNumber'] ?? null,
                'totalPoints' => $record['totalPoints'] ?? 0,
                'useablePoints' => $record['useablePoints'] ?? 0,
                'level' => $this->defaultLevelName(),
                'status' => $this->normalizeAccountStatus($record['status'] ?? 'active'),
                'statusChange' => null,
                'importStatus' => $record['importStatus'] ?? 1,
                'is_active' => $this->normalizeAccountStatus($record['status'] ?? 'active') === 'active',
                'level_obtained_at' => ! empty($record['level_obtained_at'])
                    ? Carbon::parse($record['level_obtained_at'])
                    : null,
                'expires_at' => ! empty($record['expires_at']) ? Carbon::parse($record['expires_at']) : null,
                'created_at' => ! empty($record['created_at']) ? Carbon::parse($record['created_at']) : null,
                'updated_at' => ! empty($record['updated_at']) ? Carbon::parse($record['updated_at']) : null,
            ];

            if ($guestsData !== [] || $this->recordHasGuestPayload($record)) {
                $gratitudeValues['guests_data'] = $guestsData;
            }

            $gratitude = Gratitude::updateOrCreate(
                ['old_id' => $record['id']],
                $gratitudeValues
            );

            $level = $this->pointExpiryService->resolveLevelForGratitude($gratitude);

            // Cancellation Points (processed before earned/bonus so cancel_id can be resolved)
            if (isset($record['cancellationPoints']) && is_array($record['cancellationPoints'])) {
                foreach ($record['cancellationPoints'] as $cp) {
                    if ($this->shouldSkipImportedCancellation($cp)) {
                        $this->deleteImportedCancellation($cp);

                        continue;
                    }

                    $fallback_date = $this->parseFallbackDate($cp);

                    Cancellation::updateOrCreate(
                        ['old_id' => $cp['id']],
                        [
                            'user_id' => $cp['user_id'] ?? null,
                            'points' => $cp['points'] ?? 0,
                            'reason' => $cp['reason'] ?? null,
                            'amount' => $cp['amount'] ?? 0,
                            'category' => $cp['category'] ?? null,
                            'description' => $cp['description'] ?? null,
                            'date' => $fallback_date,
                            'gratitudeNumber' => $cp['gratitudeNumber'] ?? null,
                            'points_breakdown' => $cp['points_breakdown'] ?? null,
                            'status' => $cp['status'] ?? null,
                            'created_at' => ! empty($cp['created_at']) ? Carbon::parse($cp['created_at']) : null,
                            'updated_at' => ! empty($cp['updated_at']) ? Carbon::parse($cp['updated_at']) : null,
                        ]
                    );
                }
            }

            // Earned Points
            if (isset($record['earnedPoints']) && is_array($record['earnedPoints'])) {
                foreach ($record['earnedPoints'] as $ep) {
                    $cancel_id = $this->resolveCancelId($ep['cancel_id'] ?? null);
                    $fallback_date = $this->parseFallbackDate($ep);

                    if ((int) ($ep['points'] ?? 0) < 0) {
                        $this->importNegativePointAdjustment($ep, $gratitude->gratitudeNumber, 'earned');

                        continue;
                    }

                    $usable_date = null;
                    $journeyToSave = null;
                    if (! empty($ep['journey_id']) && isset($journeysMap[$ep['journey_id']])) {
                        $journey = $journeysMap[$ep['journey_id']];
                        $journeyToSave = $journey;
                        if (! empty($journey['endDate'])) {
                            $parsedDate = Carbon::parse($journey['endDate']);
                            if ($parsedDate->year > 1970) {
                                $usable_date = $parsedDate;
                            }
                        }
                    }

                    if (! $usable_date && $fallback_date) {
                        $usable_date = $fallback_date->copy();
                    }

                    EarnedPoint::updateOrCreate(
                        ['old_id' => $ep['id']],
                        [
                            'user_id' => $ep['user_id'] ?? null,
                            'journey_id' => $ep['journey_id'] ?? null,
                            'cancel_id' => $cancel_id,
                            'gratitudeNumber' => $ep['gratitudeNumber'] ?? null,
                            'points' => $ep['points'] ?? 0,
                            'redeemed_points' => $ep['redeemed_points'] ?? 0,
                            'cancelled_points' => $ep['cancelled_points'] ?? ($cancel_id ? max(0, (int) ($ep['points'] ?? 0) - (int) ($ep['redeemed_points'] ?? 0)) : 0),
                            'redemption_history' => $ep['redemption_history'] ?? null,
                            'amount' => $ep['amount'] ?? null,
                            'date' => $fallback_date,
                            'description' => $ep['description'] ?? null,
                            'category' => $ep['category'] ?? null,
                            'status' => $this->normalizeImportedPointStatus($ep['status'] ?? null),
                            'usable_date' => $usable_date,
                            'expires_at' => $this->pointExpiryService->calculateEarnedExpiry($usable_date, $level),
                            'project_data' => $journeyToSave,
                            'created_at' => ! empty($ep['created_at']) ? Carbon::parse($ep['created_at']) : null,
                            'updated_at' => ! empty($ep['updated_at']) ? Carbon::parse($ep['updated_at']) : null,
                        ]
                    );
                }
            }

            // Bonus Points
            if (isset($record['bonusPoints']) && is_array($record['bonusPoints'])) {
                foreach ($record['bonusPoints'] as $bp) {
                    $cancel_id = $this->resolveCancelId($bp['cancel_id'] ?? null);
                    $fallback_date = $this->parseFallbackDate($bp);

                    if ((int) ($bp['points'] ?? 0) < 0) {
                        $this->importNegativePointAdjustment($bp, $gratitude->gratitudeNumber, 'bonus');

                        continue;
                    }

                    $usable_date = $fallback_date ? $fallback_date->copy() : null;

                    BonusPoint::updateOrCreate(
                        ['old_id' => $bp['id']],
                        [
                            'user_id' => $bp['user_id'] ?? null,
                            'journey_id' => $bp['journey_id'] ?? null,
                            'cancel_id' => $cancel_id,
                            'gratitudeNumber' => $bp['gratitudeNumber'] ?? null,
                            'points' => $bp['points'] ?? 0,
                            'redeemed_points' => $bp['redeemed_points'] ?? 0,
                            'cancelled_points' => $bp['cancelled_points'] ?? ($cancel_id ? max(0, (int) ($bp['points'] ?? 0) - (int) ($bp['redeemed_points'] ?? 0)) : 0),
                            'redemption_history' => $bp['redemption_history'] ?? null,
                            'amount' => $bp['amount'] ?? null,
                            'date' => $fallback_date,
                            'description' => $bp['description'] ?? null,
                            'category' => $bp['category'] ?? null,
                            'type' => $bp['type'] ?? null,
                            'status' => $this->normalizeImportedPointStatus($bp['status'] ?? null),
                            'usable_date' => $usable_date,
                            'expires_at' => $this->pointExpiryService->calculateBonusExpiry($usable_date, $level),
                            'created_at' => ! empty($bp['created_at']) ? Carbon::parse($bp['created_at']) : null,
                            'updated_at' => ! empty($bp['updated_at']) ? Carbon::parse($bp['updated_at']) : null,
                        ]
                    );
                }
            }

            // Redeem Points
            if (isset($record['redeemPoints']) && is_array($record['redeemPoints'])) {
                foreach ($record['redeemPoints'] as $rp) {
                    $cancel_id = $this->resolveCancelId($rp['cancel_id'] ?? null);
                    $redemptionDate = $this->importedRedemptionDate($rp);
                    $pointsBreakdown = is_array($rp['points_breakdown'] ?? null) ? $rp['points_breakdown'] : [];

                    if ($redemptionDate) {
                        $pointsBreakdown['imported_redemption_date'] = $redemptionDate->toDateTimeString();
                    }

                    $redemption = RedeemPoints::updateOrCreate(
                        ['old_id' => $rp['id']],
                        [
                            'user_id' => $rp['user_id'] ?? null,
                            'journey_id' => $rp['journey_id'] ?? null,
                            'cancel_id' => $cancel_id,
                            'gratitudeNumber' => $rp['gratitudeNumber'] ?? null,
                            'points' => $rp['points'] ?? 0,
                            'amount' => $rp['amount'] ?? 0,
                            'roomStatus' => $rp['roomStatus'] ?? null,
                            'reason' => $rp['description'] ?? 'Imported Redemption',
                            'category' => $rp['category'] ?? $rp['redemption_type'] ?? null,
                            'status' => $rp['status'] ?? null,
                            'points_breakdown' => $pointsBreakdown ?: null,
                        ]
                    );

                    $timestamps = [];
                    if ($redemptionDate) {
                        $timestamps['created_at'] = $redemptionDate;
                    }
                    if (! empty($rp['updated_at'])) {
                        $timestamps['updated_at'] = Carbon::parse($rp['updated_at']);
                    } elseif ($redemptionDate) {
                        $timestamps['updated_at'] = $redemptionDate;
                    }
                    if ($timestamps !== []) {
                        $redemption->forceFill($timestamps)->save();
                    }
                }
            }

            if ($gratitude->gratitudeNumber && $hasPointData) {
                $this->syncImportedCancellationBreakdown($gratitude->gratitudeNumber);
                app(TierService::class)->recalculateTier($gratitude->gratitudeNumber, 'import');
                $this->rebuildImportedRedemptionAllocations($gratitude->gratitudeNumber);
                self::syncAccountBalance($gratitude->gratitudeNumber);
            }
        }
    }

    public function importAccountsData(array $data, array $journeysMap = []): void
    {
        $this->import($data, $journeysMap);
    }

    public function importGratitudeTable(array $data): int
    {
        $imported = 0;

        foreach ($data as $record) {
            if (! is_array($record)) {
                continue;
            }

            $gratitudeNumber = $record['gratitudeNumber'] ?? null;

            if (! is_scalar($gratitudeNumber) || trim((string) $gratitudeNumber) === '') {
                continue;
            }

            $identity = ! empty($record['id'])
                ? ['old_id' => $record['id']]
                : ['gratitudeNumber' => (string) $gratitudeNumber];
            $status = $this->normalizeAccountStatus($record['status'] ?? 'active');
            $existing = Gratitude::where($identity)->first();

            $gratitudeValues = [
                'gratitudeNumber' => (string) $gratitudeNumber,
                'totalPoints' => $record['totalPoints'] ?? 0,
                'useablePoints' => $record['useablePoints'] ?? 0,
                'level' => $this->defaultLevelName(),
                'status' => $status,
                'statusChange' => null,
                'importStatus' => $record['importStatus'] ?? 1,
                'is_active' => $status === 'active',
                'level_obtained_at' => ! empty($record['level_obtained_at'])
                    ? Carbon::parse($record['level_obtained_at'])
                    : null,
                'expires_at' => ! empty($record['expires_at']) ? Carbon::parse($record['expires_at']) : null,
                'created_at' => ! empty($record['created_at']) ? Carbon::parse($record['created_at']) : null,
                'updated_at' => ! empty($record['updated_at']) ? Carbon::parse($record['updated_at']) : null,
            ];

            if ($this->recordHasGuestPayload($record)) {
                $gratitudeValues['guests_data'] = $this->gratitudeGuests(
                    $record['guests'] ?? $record['guests_data'] ?? $record['members'] ?? [],
                    $existing
                );
            }

            Gratitude::updateOrCreate($identity, $gratitudeValues);
            $imported++;
        }

        return $imported;
    }

    public function gratitudeGuests($guests, $gratitude = null): array
    {
        $incomingGuests = collect($guests ?? [])
            ->map(fn($guest) => $this->formatGratitudeGuest($guest))
            ->filter(fn($guest) => !empty($guest['guest_id']) || !empty($guest['id']))
            ->values();

        if (!$gratitude) {
            return $incomingGuests->toArray();
        }

        $existingGuests = collect($gratitude->guests_data ?? [])
            ->map(fn($guest) => (array) $guest)
            ->filter(fn($guest) => !empty($guest['guest_id']) || !empty($guest['id']))
            ->keyBy(fn($guest) => $guest['guest_id'] ?? $guest['id']);

        foreach ($incomingGuests as $guest) {
            $key = $guest['guest_id'] ?? $guest['id'];

            $existingGuests->put($key, array_merge(
                $existingGuests->get($key, []),
                $guest
            ));
        }

        return $existingGuests->values()->toArray();
    }

    private function formatGratitudeGuest($guest): array
    {
        return [
            'id' => data_get($guest, 'id'),
            'guest_id' => data_get($guest, 'guest_id'),
            'first_name' => data_get($guest, 'first_name'),
            'last_name' => data_get($guest, 'last_name'),
            'email' => data_get($guest, 'email'),
            'birthday' => data_get($guest, 'birthday'),
            'preferred_name' => data_get($guest, 'preferred_name'),
            'ownership' => data_get($guest, 'ownership'),
            'gratitudeNumber' => data_get($guest, 'gratitudeNumber'),
            'gender' => data_get($guest, 'gender'),
        ];
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
            ->filter(fn($gratitudeNumber) => is_scalar($gratitudeNumber) && trim((string) $gratitudeNumber) !== '')
            ->map(fn($gratitudeNumber) => (string) $gratitudeNumber)
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

    private function hasImportedPointDatasets(array $record): bool
    {
        foreach (['cancellationPoints', 'earnedPoints', 'bonusPoints', 'redeemPoints'] as $key) {
            if (array_key_exists($key, $record) && is_array($record[$key])) {
                return true;
            }
        }

        return false;
    }

    private function recordHasGuestPayload(array $record): bool
    {
        foreach (
            [
                'guests_data',
                'guests',
                'members',
                'primary_guest',
                'primaryGuest',
                'lead_guest',
                'leadGuest',
                'secondary_guests',
                'secondaryGuests',
                'additional_guests',
                'additionalGuests',
                'companions',
            ] as $key
        ) {
            if (array_key_exists($key, $record)) {
                return true;
            }
        }

        return false;
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

    private function parseFallbackDate(array $row): ?Carbon
    {
        foreach (['date', 'created_at'] as $field) {
            if (! empty($row[$field])) {
                $parsed = Carbon::parse($row[$field]);
                if ($parsed->year > 1970) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    private function importNegativePointAdjustment(array $row, ?string $gratitudeNumber, string $source): void
    {
        $points = abs((int) ($row['points'] ?? 0));
        if ($points === 0 || $this->isImportedExpirationAdjustment($row)) {
            $this->deleteNegativeImportedCancellation($row, $source);

            return;
        }

        $amount = $row['amount'] ?? null;
        if (is_numeric($amount)) {
            $amount = abs((float) $amount);
        }

        $values = [
            'user_id' => $row['user_id'] ?? null,
            'journey_id' => $row['journey_id'] ?? null,
            'points' => $points,
            'amount' => $amount,
            'category' => $row['category'] ?? "{$source}_adjustment",
            'description' => $row['description'] ?? "Imported {$source} point adjustment",
            'date' => $this->parseFallbackDate($row),
            'gratitudeNumber' => $row['gratitudeNumber'] ?? $gratitudeNumber,
            'points_breakdown' => [
                'import_source' => "{$source}_points",
                'imported_from_old_id' => $row['id'] ?? null,
                'original_points' => $row['points'] ?? null,
                'original_points_breakdown' => $row['points_breakdown'] ?? null,
            ],
            'status' => 'imported_adjustment',
            'created_at' => ! empty($row['created_at']) ? Carbon::parse($row['created_at']) : null,
            'updated_at' => ! empty($row['updated_at']) ? Carbon::parse($row['updated_at']) : null,
        ];

        $oldId = $this->negativeAdjustmentOldId($row['id'] ?? null, $source);

        if ($oldId === null) {
            Cancellation::create($values);

            return;
        }

        Cancellation::updateOrCreate(['old_id' => $oldId], $values);
    }

    private function isImportedExpirationAdjustment(array $row): bool
    {
        $text = strtolower(implode(' ', array_filter([
            $row['description'] ?? null,
            $row['category'] ?? null,
            $row['type'] ?? null,
        ], fn($value) => is_scalar($value) && $value !== '')));

        return str_contains($text, 'expir');
    }

    private function shouldSkipImportedCancellation(array $row): bool
    {
        return in_array($this->normalizeImportDescription($row['description'] ?? null), [
            'points expired (+2 years)',
            'points expired (2+ years)',
            'Points Expired (3+ years)',
            '2 year expiration',
            'program retired',
            'Program terminated',
            'bonus points expired',
            'expired points',
        ], true);
    }

    private function normalizeImportDescription(mixed $description): string
    {
        if (! is_scalar($description)) {
            return '';
        }

        return trim(strtolower(preg_replace('/\s+/', ' ', (string) $description)));
    }

    private function deleteImportedCancellation(array $row): void
    {
        if (! empty($row['id'])) {
            Cancellation::where('old_id', $row['id'])->delete();

            return;
        }

        $description = $this->normalizeImportDescription($row['description'] ?? null);
        if ($description === '') {
            return;
        }

        Cancellation::query()
            ->where('gratitudeNumber', $row['gratitudeNumber'] ?? null)
            ->whereRaw('LOWER(TRIM(description)) = ?', [$description])
            ->delete();
    }

    private function deleteNegativeImportedCancellation(array $row, string $source): void
    {
        $oldId = $this->negativeAdjustmentOldId($row['id'] ?? null, $source);

        if ($oldId !== null) {
            Cancellation::where('old_id', $oldId)->delete();
        }
    }

    private function negativeAdjustmentOldId(mixed $oldId, string $source): ?int
    {
        if ($oldId === null || $oldId === '') {
            return null;
        }

        $offset = $source === 'bonus' ? 2_000_000_000 : 1_000_000_000;

        return -1 * ($offset + abs((int) $oldId));
    }

    private function resolveCancelId(mixed $oldId): ?int
    {
        if (! $oldId) {
            return null;
        }
        $cancel = Cancellation::where('old_id', $oldId)->first();

        return $cancel?->id;
    }

    protected function normalizeImportedPointStatus(mixed $status): bool
    {
        if ($status === null || $status === '') {
            return true;
        }

        if (is_string($status)) {
            $status = strtolower(trim($status));
        }

        return ! in_array($status, ['expired', 'inactive', 'cancelled', 'canceled', 'false', false, 0, '0'], true);
    }

    private function syncImportedCancellationBreakdown(string $gratitudeNumber): void
    {
        $cancellations = Cancellation::where('gratitudeNumber', $gratitudeNumber)->get();

        foreach ($cancellations as $cancellation) {
            if (! empty($cancellation->points_breakdown)) {
                continue;
            }

            $sources = EarnedPoint::where('cancel_id', $cancellation->id)
                ->where('gratitudeNumber', $gratitudeNumber)
                ->get()
                ->concat(
                    BonusPoint::where('cancel_id', $cancellation->id)
                        ->where('gratitudeNumber', $gratitudeNumber)
                        ->get()
                );

            if ($sources->isEmpty()) {
                continue;
            }

            $allocations = $sources
                ->map(function ($source) use ($cancellation) {
                    $points = (int) $source->cancelled_points;
                    if ($points <= 0) {
                        $points = max(
                            0,
                            (int) $source->points - (int) $source->redeemed_points
                        );
                    }

                    if ($points <= 0) {
                        return null;
                    }

                    $effectiveDate = $this->pointEffectiveDate($source);
                    $expiresAt = $source->expires_at ? Carbon::parse($source->expires_at) : null;
                    $cancelledAt = $cancellation->date ? Carbon::parse($cancellation->date) : null;

                    return [
                        'source_type' => $source::class,
                        'source_id' => $source->id,
                        'source_old_id' => $source->old_id,
                        'points' => $points,
                        'remaining_after' => $source->remaining_points,
                        'effective_date' => $effectiveDate?->toDateString(),
                        'expires_at' => $expiresAt?->toDateString(),
                        'cancellation_date' => $cancelledAt?->toDateString(),
                    ];
                })
                ->filter()
                ->values()
                ->all();

            if (! empty($allocations)) {
                $cancellation->update(['points_breakdown' => $allocations]);
            }
        }
    }

    private function pointEffectiveDate(EarnedPoint|BonusPoint $point): ?Carbon
    {
        $date = $point->usable_date ?? $point->date ?? $point->created_at;

        return $date ? Carbon::parse($date) : null;
    }

    private function rebuildImportedRedemptionAllocations(string $gratitudeNumber): void
    {
        $redemptions = RedeemPoints::where('gratitudeNumber', $gratitudeNumber)
            ->whereNotNull('old_id')
            ->orderByRaw('COALESCE(created_at, updated_at)')
            ->orderBy('id')
            ->get();

        if ($redemptions->isEmpty()) {
            return;
        }

        RedeemPointsDetails::whereIn('redeem_id', $redemptions->pluck('id'))->delete();

        EarnedPoint::where('gratitudeNumber', $gratitudeNumber)
            ->update([
                'redeemed_points' => 0,
                'redemption_history' => null,
            ]);

        BonusPoint::where('gratitudeNumber', $gratitudeNumber)
            ->update([
                'redeemed_points' => 0,
                'redemption_history' => null,
            ]);

        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->first();
        if (! $gratitude) {
            return;
        }

        foreach ($redemptions as $redemption) {
            $this->applyHistoricalRedemptionAllocation($gratitude, $redemption);
        }
    }

    private function applyHistoricalRedemptionAllocation(Gratitude $gratitude, RedeemPoints $redemption): void
    {
        $points = max(0, (int) $redemption->points);
        if ($points <= 0) {
            return;
        }

        $redemptionDate = $this->redemptionOccurredAt($redemption);
        $redemptionType = $redemption->category ?: ($redemption->journey_id ? 'journey' : 'partner');
        $levelName = $this->levelNameAt($gratitude, $redemptionDate);
        $level = GratitudeLevel::where('name', $levelName)->first();
        $pointsPerDollar = $this->pointsPerDollarForRedemption($level, $redemptionType);
        $calculatedAmount = round($points / $pointsPerDollar, 2);
        $queue = $this->pointLedgerService->redeemableQueue($gratitude->gratitudeNumber, $redemptionDate);
        $usablePointsAtRedemption = (int) $queue->sum(fn($segment) => (int) $segment->available_points);
        $pointsRemaining = $points;

        foreach ($queue as $segment) {
            if ($pointsRemaining <= 0) {
                break;
            }

            $available = (int) $segment->available_points;
            if ($available <= 0) {
                continue;
            }

            $toDeduct = min($available, $pointsRemaining);
            $history = is_array($segment->redemption_history) ? $segment->redemption_history : [];
            $history[] = [
                'redemption_id' => $redemption->id,
                'date' => $redemptionDate->toDateString(),
                'points' => $toDeduct,
                'amount' => round($toDeduct / $pointsPerDollar, 2),
                'reason' => $redemption->reason ?: 'Imported Redemption',
                'redemption_type' => $redemptionType,
                'level_at_redemption' => $levelName,
                'points_per_dollar' => $pointsPerDollar,
            ];

            $segment->getConnection()
                ->table($segment->getTable())
                ->where('id', $segment->id)
                ->update([
                    'redeemed_points' => (int) $segment->redeemed_points + $toDeduct,
                    'redemption_history' => json_encode($history),
                    'updated_at' => Carbon::now(),
                ]);

            $segment->redeemed_points += $toDeduct;
            $segment->available_points -= $toDeduct;

            RedeemPointsDetails::create([
                'user_id' => $redemption->user_id,
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
            ]);

            $pointsRemaining -= $toDeduct;
        }

        $redemption->update([
            'amount' => $calculatedAmount,
            'category' => $redemptionType,
            'status' => $redemption->status ?: 'approved',
            'points_breakdown' => array_merge($redemption->points_breakdown ?? [], [
                'redemption_type' => $redemptionType,
                'level_at_redemption' => $levelName,
                'points_per_dollar' => $pointsPerDollar,
                'redemption_date' => $redemptionDate->toDateString(),
                'usable_points_at_redemption' => $usablePointsAtRedemption,
                'calculated_amount' => $calculatedAmount,
                'unallocated_points' => max(0, $pointsRemaining),
                'recalculated_from_import' => true,
            ]),
        ]);
    }

    private function importedRedemptionDate(array $row): ?Carbon
    {
        foreach (['date', 'redemption_date', 'redeemed_at', 'created_at', 'updated_at'] as $field) {
            if (! empty($row[$field])) {
                return Carbon::parse($row[$field]);
            }
        }

        return null;
    }

    private function redemptionOccurredAt(RedeemPoints $redemption): Carbon
    {
        return $redemption->created_at
            ? Carbon::parse($redemption->created_at)
            : ($redemption->updated_at ? Carbon::parse($redemption->updated_at) : Carbon::now());
    }

    private function levelNameAt(Gratitude $gratitude, CarbonInterface $date): string
    {
        $history = collect($gratitude->levelHistory ?? [])
            ->filter(fn($entry) => is_array($entry) && ! empty($entry['date']))
            ->filter(fn($entry) => Carbon::parse($entry['date'])->lte($date))
            ->sortBy(fn($entry) => Carbon::parse($entry['date'])->timestamp)
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
                        'usable_points_at_redemption' => $availableSum,
                        'calculated_amount' => $monetaryValue,
                        'journey_data' => $data['journey_data'] ?? null,
                    ],
                    'status' => 'approved',
                ]);

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

                    RedeemPointsDetails::create([
                        'user_id' => $data['user_id'] ?? null,
                        'redeem_id' => $redemption->id,
                        'source_id' => $segment->id,
                        'source_type' => get_class($segment),
                        'points' => $toDeduct,
                    ]);

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
                        array_filter($history, fn($entry) => ($entry['redemption_id'] ?? null) != $id)
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

    public static function syncAccountBalance($gratitudeNumber)
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

        // Recalculate tier from earned points — skipped when systemLevelUpdate = false (manual override)
        if ($gratitude->gratitudeNumber && $gratitude->systemLevelUpdate) {
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
