<?php

namespace App\Services\Import;

use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\Cancellation;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeBenefit;
use App\Models\Gratitude\GratitudeLevel;
use App\Models\Gratitude\RedeemPoints;
use App\Models\Gratitude\RedeemPointsDetails;
use App\Services\Gratitude\GratitudeBenefitsService;
use App\Services\Gratitude\GratitudeService;
use App\Services\Gratitude\PointExpiryService;
use App\Services\Gratitude\PointLedgerService;
use App\Services\Gratitude\TierService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GratitudeImportService
{
    private const ACCOUNT_IMPORT_CHUNK_SIZE = 100;

    private const REMOTE_IMPORT_CONCURRENCY = 15;

    private const LEGACY_BALANCE_TRANSFER_DESCRIPTION = 'Balance transfer from old system';

    private const LEGACY_BALANCE_TRANSFER_EXPIRES_AT = '2024-12-31';

    public function __construct(
        protected PointExpiryService $pointExpiryService,
        protected PointLedgerService $pointLedgerService,
        protected GratitudeBenefitsService $benefitsService,
    ) {}

    public function importExternalPayload(mixed $data): array
    {
        if (empty($data) || ! is_array($data)) {
            $this->logImportWarning('External gratitude import received an invalid payload.', [
                'payload_type' => get_debug_type($data),
            ]);

            return $this->result(['message' => 'Invalid data format or empty payload'], 400);
        }

        try {
            DB::transaction(fn () => $this->import($data));

            return $this->result(['message' => 'Data imported successfully']);
        } catch (\Throwable $e) {
            $this->logImportException('External gratitude import failed.', $e, [
                'records' => count($data),
            ]);

            return $this->result([
                'message' => 'Failed to import data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function importGratitudes(?string $status = null): array
    {
        $this->prepareLongRunningImport();

        $importStatus = $this->normalizeImportStatus($status);
        $baseUrl = rtrim((string) config('services.aivteam.base_url'), '/');
        $gratitudesUrl = $baseUrl.'/api/gratitude/get/gratitude-data-all-by-status/gratitude/'.$importStatus;

        try {
            $getResponse = $this->aivteamHttp()->get($gratitudesUrl);
        } catch (\Throwable $e) {
            $this->logImportException('Remote gratitude full import request threw an exception.', $e, [
                'status' => $importStatus,
                'url' => $gratitudesUrl,
            ]);

            return $this->result([
                'message' => 'Failed to fetch data from remote API All Gratitudes',
                'error' => $e->getMessage(),
            ], 500);
        }

        if (! $getResponse->successful()) {
            $this->logImportWarning('Remote gratitude full import request failed.', [
                'status' => $importStatus,
                'url' => $gratitudesUrl,
                ...$this->responseContext($getResponse),
            ]);

            return $this->result([
                'message' => 'Failed to fetch data from remote API All Gratitudes',
                'status' => $getResponse->status(),
            ], 500);
        }

        $records = $this->normalizeRemoteList($getResponse->json(), ['data', 'gratitudes']);

        if (empty($records)) {
            $this->logImportWarning('Remote gratitude full import returned an empty or invalid payload.', [
                'status' => $importStatus,
                'url' => $gratitudesUrl,
                'response_body' => $this->truncate($getResponse->body()),
            ]);

            return $this->result([
                'message' => 'Invalid data format or empty payload',
            ], 400);
        }

        $validRecords = [];
        $accountsWithPointData = 0;

        $pointTotals = [
            'cancellation_points' => 0,
            'earned_points' => 0,
            'bonus_points' => 0,
            'redeem_points' => 0,
        ];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $gratitudeNumber = $record['gratitudeNumber'] ?? null;

            if (! is_scalar($gratitudeNumber) || trim((string) $gratitudeNumber) === '') {
                continue;
            }

            $validRecords[] = $record;

            if ($this->hasImportedPointDatasets($record)) {
                $accountsWithPointData++;
            }

            $pointTotals['cancellation_points'] += is_array($record['cancellationPoints'] ?? null)
                ? count($record['cancellationPoints'])
                : 0;

            $pointTotals['earned_points'] += is_array($record['earnedPoints'] ?? null)
                ? count($record['earnedPoints'])
                : 0;

            $pointTotals['bonus_points'] += is_array($record['bonusPoints'] ?? null)
                ? count($record['bonusPoints'])
                : 0;

            $pointTotals['redeem_points'] += is_array($record['redeemPoints'] ?? null)
                ? count($record['redeemPoints'])
                : 0;
        }

        if (empty($validRecords)) {
            $this->logImportWarning('Remote gratitude full import had no valid account records.', [
                'status' => $importStatus,
                'records' => count($records),
            ]);

            return $this->result([
                'message' => 'No valid gratitude records found in payload',
                'summary_accounts' => count($records),
            ], 400);
        }

        try {
            $journeysMap = $this->fetchRemoteJourneysMap($baseUrl);

            DB::transaction(function () use ($validRecords, $journeysMap) {
                $this->import($validRecords, $journeysMap);
            });
        } catch (\Throwable $e) {
            $this->logImportException('Gratitude full import failed while saving records and points.', $e, [
                'status' => $importStatus,
                'records' => count($validRecords),
                'point_totals' => $pointTotals,
            ]);

            return $this->result([
                'message' => 'Gratitude import failed: '.$e->getMessage(),
            ], 500);
        }

        return $this->result([
            'message' => ucfirst($importStatus).' gratitude data imported successfully',
            'import_status' => $importStatus,
            'summary_accounts' => count($records),
            'imported_accounts' => count($validRecords),
            'accounts_with_point_data' => $accountsWithPointData,
            ...$pointTotals,
        ]);
    }

    public function importAccountData(?string $status = null): array
    {
        $this->prepareLongRunningImport();

        $importStatus = $this->normalizeImportStatus($status);
        $baseUrl = rtrim((string) config('services.aivteam.base_url'), '/');
        $journeysMap = $this->fetchRemoteJourneysMap($baseUrl);
        $isActive = $importStatus === 'active';
        $summaryAccounts = 0;
        $detailedAccounts = 0;
        $syncedAccounts = 0;
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
            $query->chunkById(self::ACCOUNT_IMPORT_CHUNK_SIZE, function ($gratitudes) use ($baseUrl, $journeysMap, &$summaryAccounts, &$detailedAccounts, &$syncedAccounts, &$detailFailures) {
                $summaryRecords = $gratitudes
                    ->map(fn (Gratitude $gratitude) => $gratitude->toArray())
                    ->all();

                $summaryAccounts += count($summaryRecords);

                [$detailedRecords, $failures] = $this->fetchRemoteGratitudeAccountDetails($summaryRecords, $baseUrl);
                $detailFailures = array_merge($detailFailures, $failures);

                if ($failures !== []) {
                    $this->logImportWarning('Remote gratitude account detail import had failures.', [
                        'failures' => $failures,
                        'failure_count' => count($failures),
                    ]);
                }

                if ($detailedRecords === []) {
                    return;
                }

                DB::transaction(fn () => $this->importAccountsData(array_values($detailedRecords), $journeysMap));
                $detailedAccounts += count($detailedRecords);
                $syncedAccounts += count($detailedRecords);
            });
        } catch (\Throwable $e) {
            $this->logImportException('Account data import failed.', $e, [
                'status' => $importStatus,
                'summary_accounts' => $summaryAccounts,
                'detailed_accounts' => $detailedAccounts,
                'detail_failures' => count($detailFailures),
            ]);

            return $this->result(['message' => 'Account data import failed: '.$e->getMessage()], 500);
        }

        return $this->result([
            'message' => ucfirst($importStatus).' account data imported successfully',
            'import_status' => $importStatus,
            'summary_accounts' => $summaryAccounts,
            'detailed_accounts' => $detailedAccounts,
            'detail_failures' => count($detailFailures),
            'failed_detail_accounts' => $detailFailures,
            'synced_accounts' => $syncedAccounts,
        ]);
    }

    public function importAccount(string $gratitudeNumber): array
    {
        $this->prepareLongRunningImport();

        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $baseUrl = rtrim((string) config('services.aivteam.base_url'), '/');
        $journeysMap = $this->fetchRemoteJourneysMap($baseUrl);
        $summaryRecord = $gratitude->toArray();

        if ($gratitude->old_id) {
            $summaryRecord['id'] = $gratitude->old_id;
        } else {
            unset($summaryRecord['id']);
        }

        [$detailedRecords, $failures] = $this->fetchRemoteGratitudeAccountDetails([$summaryRecord], $baseUrl);
        $detailRecord = array_values($detailedRecords)[0] ?? null;

        if ($failures !== []) {
            $this->logImportWarning('Single gratitude account detail import had remote failures.', [
                'gratitudeNumber' => $gratitudeNumber,
                'failures' => $failures,
                'failure_count' => count($failures),
            ]);
        }

        if (! $detailRecord) {
            $this->logImportWarning('Single gratitude account import failed because no detail payload was found.', [
                'gratitudeNumber' => $gratitudeNumber,
                'failures' => $failures,
            ]);

            return $this->result([
                'message' => 'Account data import failed: no remote detail payload was found.',
                'detail_failures' => count($failures),
                'failed_detail_accounts' => $failures,
            ], 422);
        }

        if (empty($detailRecord['id'])) {
            if (! $gratitude->old_id) {
                $this->logImportWarning('Single gratitude account import failed because the remote payload is missing the legacy id.', [
                    'gratitudeNumber' => $gratitudeNumber,
                ]);

                return $this->result([
                    'message' => 'Account data import failed: remote payload is missing the legacy account id.',
                ], 422);
            }

            $detailRecord['id'] = $gratitude->old_id;
        }

        try {
            DB::transaction(fn () => $this->importAccountsData([$detailRecord], $journeysMap));
        } catch (\Throwable $e) {
            $this->logImportException('Single gratitude account import failed while saving records.', $e, [
                'gratitudeNumber' => $gratitudeNumber,
                ...$this->recordContext($detailRecord),
            ]);

            return $this->result(['message' => 'Account data import failed: '.$e->getMessage()], 500);
        }

        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        return $this->result([
            'message' => 'Account imported successfully',
            'gratitude_number' => $gratitude->gratitudeNumber,
            'detail_failures' => count($failures),
            'failed_detail_accounts' => $failures,
            'gratitude' => $gratitude,
        ]);
    }

    public function importBenefits(): array
    {
        $this->prepareLongRunningImport();

        $benefitsUrl = 'https://artinvoyage.com/wp-json/api/all-gratitude-benefits';

        try {
            $getResponse = Http::timeout(120)->get($benefitsUrl);
        } catch (\Throwable $e) {
            $this->logImportException('Gratitude benefits import request threw an exception.', $e, [
                'url' => $benefitsUrl,
            ]);

            return $this->result(['success' => false, 'message' => 'Failed to import benefits: '.$e->getMessage()], 500);
        }

        if (! $getResponse || ! $getResponse->successful()) {
            $this->logImportWarning('Gratitude benefits import request failed.', [
                'url' => $benefitsUrl,
                ...$this->responseContext($getResponse),
            ]);

            return $this->result(['success' => false, 'message' => 'Failed to import benefits'], 500);
        }

        $data = $getResponse->json();

        if (! isset($data['benefits']) || ! is_array($data['benefits'])) {
            $this->logImportWarning('Gratitude benefits import returned an invalid payload.', [
                'response_body' => $this->truncate($getResponse->body()),
            ]);

            return $this->result(['success' => false, 'message' => 'Failed to import benefits'], 500);
        }

        $levels = GratitudeLevel::get();
        $explorerLevel = $levels->first(fn ($level) => stripos($level->name, 'Explorer') !== false);
        $globetrotterLevel = $levels->first(fn ($level) => stripos($level->name, 'Globetrotter') !== false);
        $jetsetterLevel = $levels->first(fn ($level) => stripos($level->name, 'Jetsetter') !== false || stripos($level->name, 'Jetesetter') !== false);

        $benefitNames = collect($data['benefits'])
            ->pluck('gratitude_benefit.benefit_name')
            ->filter()
            ->unique()
            ->values();

        $existingBenefits = GratitudeBenefit::whereIn('name', $benefitNames)
            ->get()
            ->keyBy('name');

        $imported = 0;

        try {
            DB::transaction(function () use (
                $data,
                $existingBenefits,
                $explorerLevel,
                $globetrotterLevel,
                $jetsetterLevel,
                &$imported
            ) {
                foreach ($data['benefits'] as $value) {
                    $benefitData = $value['gratitude_benefit'] ?? null;
                    $benefitName = $benefitData['benefit_name'] ?? null;

                    if (! $benefitData || ! $benefitName) {
                        continue;
                    }

                    $benefit = $existingBenefits->get($benefitName);

                    $attributes = [
                        'description' => $benefitData['benefit_description'] ?? null,
                        'benefit_key' => $benefit?->benefit_key ?: $this->benefitsService->generateBenefitKey($benefitName),
                        'is_active' => 1,
                    ];

                    if ($benefit) {
                        $benefit->update($attributes);
                    } else {
                        $benefit = GratitudeBenefit::create(array_merge(
                            ['name' => $benefitName],
                            $attributes
                        ));
                        $existingBenefits->put($benefitName, $benefit);
                    }

                    $syncData = [];

                    if ($explorerLevel && ! empty($value['gratitude_explorer'])) {
                        $syncData[$explorerLevel->id] = $this->levelSyncData($value['gratitude_explorer']);
                    }
                    if ($globetrotterLevel && ! empty($value['gratitude_globetrotter'])) {
                        $syncData[$globetrotterLevel->id] = $this->levelSyncData($value['gratitude_globetrotter']);
                    }
                    if ($jetsetterLevel && ! empty($value['gratitude_jetsetter'])) {
                        $syncData[$jetsetterLevel->id] = $this->levelSyncData($value['gratitude_jetsetter']);
                    }

                    $benefit->levels()->sync($syncData);
                    $imported++;
                }
            });
        } catch (\Throwable $e) {
            $this->logImportException('Gratitude benefits import failed while saving records.', $e, [
                'benefit_count' => count($data['benefits']),
                'imported_before_failure' => $imported,
            ]);

            return $this->result(['success' => false, 'message' => 'Failed to import benefits: '.$e->getMessage()], 500);
        }

        return $this->result([
            'success' => true,
            'message' => 'Benefits imported successfully',
            'imported' => $imported,
        ]);
    }

    public function import(array $data, array $journeysMap = []): void
    {
        $currentRecord = null;

        try {
            foreach ($data as $record) {
                $currentRecord = is_array($record) ? $record : null;
                $hasPointData = $this->hasImportedPointDatasets($record);
                $guestsData = Gratitude::extractGuestsData($record);

                $gratitudeValues = [
                    'gratitudeNumber' => $record['gratitudeNumber'] ?? null,
                    'totalPoints' => $record['totalPoints'] ?? 0,
                    'useablePoints' => $record['useablePoints'] ?? 0,
                    'level' => $this->defaultLevelName(),
                    'status' => $this->normalizeAccountStatus($record['status'] ?? 'active'),
                    'statusChange' => null,
                    'systemLevelUpdate' => true,
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
                                'amount' => $cp['amount'] ?? 0,
                                'category' => $cp['category'] ?? null,
                                'description' => $cp['description'] ?? $cp['reason'] ?? null,
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
                                'cancelled_points' => $this->importedCancelledPoints($ep, $cancel_id),
                                'redemption_history' => $ep['redemption_history'] ?? null,
                                'amount' => $ep['amount'] ?? null,
                                'date' => $fallback_date,
                                'description' => $ep['description'] ?? null,
                                'category' => $ep['category'] ?? null,
                                'status' => $this->normalizeImportedPointStatus($ep['status'] ?? null),
                                'usable_date' => $usable_date,
                                'expires_at' => $this->importedEarnedExpiry($ep, $usable_date, $level),
                                'project_data' => $journeyToSave,
                                'created_at' => ! empty($ep['created_at']) ? Carbon::parse($ep['created_at']) : null,
                                'updated_at' => ! empty($ep['updated_at']) ? Carbon::parse($ep['updated_at']) : null,
                            ]
                        );
                    }
                }

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
                                'cancelled_points' => $this->importedCancelledPoints($bp, $cancel_id),
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
                    GratitudeService::syncAccountBalance($gratitude->gratitudeNumber, false);
                }
            }
        } catch (\Throwable $e) {
            $this->logImportException('Gratitude import failed while processing a record.', $e, [
                'records' => count($data),
                ...$this->recordContext($currentRecord),
            ]);

            throw $e;
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
                'systemLevelUpdate' => true,
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
            ->map(fn ($guest) => $this->formatGratitudeGuest($guest))
            ->filter(fn ($guest) => ! empty($guest['guest_id']) || ! empty($guest['id']))
            ->values();

        if (! $gratitude) {
            return $incomingGuests->toArray();
        }

        $existingGuests = collect($gratitude->guests_data ?? [])
            ->map(fn ($guest) => (array) $guest)
            ->filter(fn ($guest) => ! empty($guest['guest_id']) || ! empty($guest['id']))
            ->keyBy(fn ($guest) => $guest['guest_id'] ?? $guest['id']);

        foreach ($incomingGuests as $guest) {
            $key = $guest['guest_id'] ?? $guest['id'];

            $existingGuests->put($key, array_merge(
                $existingGuests->get($key, []),
                $guest
            ));
        }

        return $existingGuests->values()->toArray();
    }

    private function result(array $data, int $status = 200): array
    {
        return [
            'data' => $data,
            'status' => $status,
        ];
    }

    private function logImportWarning(string $message, array $context = []): void
    {
        Log::warning($message, $this->logContext($context));
    }

    private function logImportException(string $message, \Throwable $exception, array $context = []): void
    {
        Log::error($message, $this->logContext([
            ...$context,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception' => $exception,
        ]));
    }

    private function logContext(array $context = []): array
    {
        return [
            'import' => 'gratitude',
            ...$context,
        ];
    }

    private function responseContext(?Response $response): array
    {
        if (! $response) {
            return [
                'response_status' => null,
                'response_body' => null,
            ];
        }

        return [
            'response_status' => $response->status(),
            'response_body' => $this->truncate($response->body()),
        ];
    }

    private function recordContext(?array $record): array
    {
        if (! $record) {
            return [];
        }

        return [
            'gratitudeNumber' => $record['gratitudeNumber'] ?? null,
            'old_id' => $record['id'] ?? $record['old_id'] ?? null,
            'point_counts' => [
                'cancellations' => is_array($record['cancellationPoints'] ?? null) ? count($record['cancellationPoints']) : null,
                'earned' => is_array($record['earnedPoints'] ?? null) ? count($record['earnedPoints']) : null,
                'bonus' => is_array($record['bonusPoints'] ?? null) ? count($record['bonusPoints']) : null,
                'redeem' => is_array($record['redeemPoints'] ?? null) ? count($record['redeemPoints']) : null,
            ],
        ];
    }

    private function truncate(?string $value, int $limit = 2000): ?string
    {
        if ($value === null) {
            return null;
        }

        return strlen($value) > $limit
            ? substr($value, 0, $limit).'...'
            : $value;
    }

    private function aivteamHttp(): PendingRequest
    {
        return Http::withoutVerifying()
            ->withToken(config('services.aivteam.access_token'))
            ->timeout(600);
    }

    private function prepareLongRunningImport(): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        DB::disableQueryLog();
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
        $journeysUrl = $baseUrl.'/api/get/all/journeys';

        try {
            $getJourneysData = $this->aivteamHttp()->get($journeysUrl);
        } catch (\Throwable $e) {
            $this->logImportException('Remote journeys map request threw an exception during gratitude import.', $e, [
                'url' => $journeysUrl,
            ]);

            return $journeysMap;
        }

        if (! $getJourneysData->successful()) {
            $this->logImportWarning('Remote journeys map request failed during gratitude import.', [
                'url' => $journeysUrl,
                ...$this->responseContext($getJourneysData),
            ]);

            return $journeysMap;
        }

        foreach ($this->normalizeRemoteList($getJourneysData->json(), ['data', 'journeys']) as $journey) {
            if (isset($journey['id'])) {
                $journeysMap[$journey['id']] = $journey;
            }
        }

        return $journeysMap;
    }

    private function fetchRemoteGratitudeAccountDetails(array $summaryRecords, string $baseUrl): array
    {
        $detailedRecords = [];
        $failures = [];
        $summaryByNumber = [];
        $detailUrls = [];

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
            $summaryByNumber[$gratitudeNumber] = $summaryRecord;
            $detailUrls[$gratitudeNumber] = $baseUrl.'/api/gratitude/get/gratitude-data-all/gratitude/'.rawurlencode($gratitudeNumber);
        }

        $this->logImportWarning('Failed to fetch remote gratitude account details', [
            'message' => 'This may be due to remote request failures or invalid summary records. Check the failures array for details.',
            'summary_count' => count($summaryRecords),
            'detail_request_count' => count($detailUrls),
            'failures_before_requests' => count($failures),
            'failures' => $failures,
        ]);

        $detailResponses = $this->poolAivteamGet($detailUrls);
        $guestUrls = [];
        $detailPayloads = [];

        foreach ($summaryByNumber as $gratitudeNumber => $summaryRecord) {
            $response = $detailResponses[$gratitudeNumber] ?? null;

            if ($response instanceof \Throwable) {
                $failures[] = [
                    'gratitudeNumber' => $gratitudeNumber,
                    'message' => $response->getMessage(),
                ];

                continue;
            }

            if (! $response instanceof Response || ! $response->successful()) {
                $failures[] = [
                    'gratitudeNumber' => $gratitudeNumber,
                    'status' => $response instanceof Response ? $response->status() : null,
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

            $detailPayloads[$gratitudeNumber] = $payload;
            $detailedRecords[$gratitudeNumber] = $detailRecord;
            $guestUrls[$gratitudeNumber] = $baseUrl.'/api/gratitude/get/gratitude-by-number/'.rawurlencode($gratitudeNumber);
        }

        $guestResponses = $this->poolAivteamGet($guestUrls, 60);

        foreach ($detailedRecords as $gratitudeNumber => $detailRecord) {
            $guestData = $this->guestDataFromResponse($guestResponses[$gratitudeNumber] ?? null);

            if ($guestData !== null) {
                $detailRecord['guests_data'] = $guestData;
            } else {
                $guestData = Gratitude::extractGuestsData($detailPayloads[$gratitudeNumber] ?? []);

                if ($guestData !== []) {
                    $detailRecord['guests_data'] = $guestData;
                }
            }

            $detailedRecords[$gratitudeNumber] = $detailRecord;
        }

        return [$detailedRecords, $failures];
    }

    private function poolAivteamGet(array $urls, int $timeout = 600): array
    {
        if ($urls === []) {
            return [];
        }

        try {
            return Http::pool(function (Pool $pool) use ($timeout, $urls) {
                foreach ($urls as $key => $url) {
                    $pool->as((string) $key)
                        ->withoutVerifying()
                        ->withToken(config('services.aivteam.access_token'))
                        ->timeout($timeout)
                        ->get($url);
                }
            }, self::REMOTE_IMPORT_CONCURRENCY);
        } catch (\Throwable $e) {
            $this->logImportException('Remote gratitude pooled import request threw an exception.', $e, [
                'url_count' => count($urls),
                'timeout' => $timeout,
            ]);

            throw $e;
        }
    }

    private function guestDataFromResponse(mixed $response): ?array
    {
        return $response instanceof Response && $response->successful()
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

    private function asList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            $values = array_values($value);
            $items = $values && collect($values)->every(fn ($item) => is_array($item))
                ? $values
                : [$value];
        } else {
            $items = $value;
        }

        return array_values(array_filter($items, fn ($item) => is_array($item)));
    }

    private function levelSyncData(mixed $value): array
    {
        $value = is_scalar($value) ? (string) $value : json_encode($value);

        return [
            'value' => $value,
            'description' => $value,
            'is_active' => 1,
            'web_status' => 1,
        ];
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

    private function importedEarnedExpiry(array $row, ?CarbonInterface $usableDate, ?GratitudeLevel $level): ?Carbon
    {
        $description = trim((string) ($row['description'] ?? ''));

        if (strcasecmp($description, self::LEGACY_BALANCE_TRANSFER_DESCRIPTION) === 0) {
            return Carbon::parse(self::LEGACY_BALANCE_TRANSFER_EXPIRES_AT);
        }

        return $this->pointExpiryService->calculateEarnedExpiry($usableDate, $level);
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

    private function importedCancelledPoints(array $row, ?int $cancelId): int
    {
        if (! $cancelId) {
            return 0;
        }

        return (int) ($row['cancelled_points'] ?? max(
            0,
            (int) ($row['points'] ?? 0) - (int) ($row['redeemed_points'] ?? 0)
        ));
    }

    private function isImportedExpirationAdjustment(array $row): bool
    {
        return $this->shouldSkipImportedCancellation($row);
    }

    private function shouldSkipImportedCancellation(array $row): bool
    {
        foreach ($this->importedCancellationTextCandidates($row) as $text) {
            if ($this->matchesSkippedImportedCancellationText($text)) {
                return true;
            }
        }

        return false;
    }

    private function importedCancellationTextCandidates(array $row): array
    {
        return collect(['description', 'reason', 'category', 'type', 'status'])
            ->map(fn ($key) => $this->normalizeImportDescription($row[$key] ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function matchesSkippedImportedCancellationText(string $text): bool
    {
        $exactMatches = [
            'points expired (+2 years)',
            'points expired (2+ years)',
            'points expired (3+ years)',
            'no longer awarding birthday points',
            '2 year expiration',
            'program retired',
            'program terminated',
            'bonus points expired',
            'expired points',
            'points expire',
            'points expired',
            'expiration 2 years',
            'cancel',
            'canceled',
            'cancelled',
        ];

        if (in_array($text, $exactMatches, true)) {
            return true;
        }

        if (str_contains($text, 'expir')) {
            return true;
        }

        if (str_contains($text, 'program') && (str_contains($text, 'retir') || str_contains($text, 'terminat'))) {
            return true;
        }

        if (str_contains($text, 'birthday') && str_contains($text, 'point')) {
            return true;
        }

        return preg_match('/\bpoints?\b.*\bcancell?e?d?\b|\bcancell?e?d?\b.*\bpoints?\b/', $text) === 1;
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
        $cancellations = collect();
        $oldId = $row['id'] ?? $row['old_id'] ?? null;

        if ($oldId !== null && $oldId !== '') {
            $cancellations = $cancellations->merge(
                Cancellation::where('old_id', $oldId)->get()
            );
        }

        $gratitudeNumber = $row['gratitudeNumber'] ?? null;

        if (is_scalar($gratitudeNumber) && trim((string) $gratitudeNumber) !== '') {
            $cancellations = $cancellations->merge(
                Cancellation::where('gratitudeNumber', (string) $gratitudeNumber)
                    ->get()
                    ->filter(fn (Cancellation $cancellation) => $this->shouldSkipImportedCancellation([
                        'description' => $cancellation->description,
                        'category' => $cancellation->category,
                        'status' => $cancellation->status,
                    ]))
            );
        }

        $this->deleteCancellationsAndUnlinkPoints($cancellations);
    }

    private function deleteNegativeImportedCancellation(array $row, string $source): void
    {
        $oldId = $this->negativeAdjustmentOldId($row['id'] ?? null, $source);

        if ($oldId !== null) {
            $this->deleteCancellationsAndUnlinkPoints(
                Cancellation::where('old_id', $oldId)->get()
            );
        }
    }

    private function deleteCancellationsAndUnlinkPoints($cancellations): void
    {
        $cancellations = collect($cancellations)
            ->filter()
            ->unique('id')
            ->values();

        $cancellationIds = $cancellations
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if ($cancellationIds === []) {
            return;
        }

        EarnedPoint::whereIn('cancel_id', $cancellationIds)
            ->update([
                'cancel_id' => null,
                'cancelled_points' => 0,
            ]);

        BonusPoint::whereIn('cancel_id', $cancellationIds)
            ->update([
                'cancel_id' => null,
                'cancelled_points' => 0,
            ]);

        $cancellations->each(fn (Cancellation $cancellation) => $cancellation->delete());
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

        if ($cancel && $this->shouldSkipImportedCancellation([
            'description' => $cancel->description,
            'category' => $cancel->category,
            'status' => $cancel->status,
        ])) {
            $this->deleteCancellationsAndUnlinkPoints(collect([$cancel]));

            return null;
        }

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
        $usablePointsAtRedemption = (int) $queue->sum(fn ($segment) => (int) $segment->available_points);
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

            $this->createRedemptionDetail([
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
            ], $redemptionDate);

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
        $breakdown = is_array($redemption->points_breakdown) ? $redemption->points_breakdown : [];

        foreach (['redemption_date', 'imported_redemption_date', 'date', 'redeemed_at'] as $field) {
            if (! empty($breakdown[$field])) {
                return Carbon::parse($breakdown[$field]);
            }
        }

        return $redemption->created_at
            ? Carbon::parse($redemption->created_at)
            : ($redemption->updated_at ? Carbon::parse($redemption->updated_at) : Carbon::now());
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

    private function pointsPerDollarForRedemption(?GratitudeLevel $level, ?string $redemptionType): float
    {
        $type = strtolower((string) ($redemptionType ?: 'journey'));

        $rate = $type === 'partner'
            ? ($level?->partner_points_per_dollar ?: $level?->redemption_points_per_dollar)
            : $level?->redemption_points_per_dollar;

        return max(1, (float) ($rate ?: 35));
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
}
