<?php

namespace App\Http\Controllers\InternalApi\Import;

use App\Http\Controllers\Controller;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeBenefit;
use App\Models\Gratitude\GratitudeLevel;
use App\Services\Gratitude\GratitudeBenefitsService;
use App\Services\Gratitude\GratitudeService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GratitudeImportData extends Controller
{
    private const ACCOUNT_IMPORT_CHUNK_SIZE = 100;

    private const REMOTE_IMPORT_CONCURRENCY = 15;

    public function __construct(
        protected GratitudeService $gratitudeService,
        protected GratitudeBenefitsService $benefitsService,
    ) {}

    public function import(?string $status = null)
    {
        return $this->importGratitudes($status);
    }

    public function importGratitudes(?string $status = null)
    {
        $this->authorizeDeveloperImport();
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

    public function importAccountData(?string $status = null)
    {
        $this->authorizeDeveloperImport();
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

                if ($detailedRecords === []) {
                    return;
                }

                DB::transaction(fn () => $this->gratitudeService->importAccountsData(array_values($detailedRecords), $journeysMap));
                $detailedAccounts += count($detailedRecords);
                $syncedAccounts += count($detailedRecords);
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Account data import failed: '.$e->getMessage()], 500);
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

    public function importAccount(string $gratitudeNumber)
    {
        $this->authorizeDeveloperImport();
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

        if (! $detailRecord) {
            return response()->json([
                'message' => 'Account data import failed: no remote detail payload was found.',
                'detail_failures' => count($failures),
                'failed_detail_accounts' => $failures,
            ], 422);
        }

        if (empty($detailRecord['id'])) {
            if (! $gratitude->old_id) {
                return response()->json([
                    'message' => 'Account data import failed: remote payload is missing the legacy account id.',
                ], 422);
            }

            $detailRecord['id'] = $gratitude->old_id;
        }

        try {
            DB::transaction(fn () => $this->gratitudeService->importAccountsData([$detailRecord], $journeysMap));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Account data import failed: '.$e->getMessage()], 500);
        }

        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();

        return response()->json([
            'message' => 'Account imported successfully',
            'gratitude_number' => $gratitude->gratitudeNumber,
            'detail_failures' => count($failures),
            'failed_detail_accounts' => $failures,
            'gratitude' => $gratitude,
        ]);
    }

    public function importBenefits()
    {
        $this->authorizeDeveloperImport();
        $this->prepareLongRunningImport();

        $getResponse = Http::timeout(120)->get('https://artinvoyage.com/wp-json/api/all-gratitude-benefits');

        if (! $getResponse || ! $getResponse->successful()) {
            return response()->json(['success' => false, 'message' => 'Failed to import benefits'], 500);
        }

        $data = $getResponse->json();

        if (! isset($data['benefits']) || ! is_array($data['benefits'])) {
            return response()->json(['success' => false, 'message' => 'Failed to import benefits'], 500);
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

        return response()->json([
            'success' => true,
            'message' => 'Benefits imported successfully',
            'imported' => $imported,
        ]);
    }

    private function aivteamHttp(): PendingRequest
    {
        return Http::withoutVerifying()
            ->withToken(config('services.aivteam.access_token'))
            ->timeout(600);
    }

    private function authorizeDeveloperImport(): void
    {
        abort_unless(request()->user()?->hasRole('Developer'), 403, 'Only developers can run imports.');
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

        return Http::pool(function (Pool $pool) use ($timeout, $urls) {
            foreach ($urls as $key => $url) {
                $pool->as((string) $key)
                    ->withoutVerifying()
                    ->withToken(config('services.aivteam.access_token'))
                    ->timeout($timeout)
                    ->get($url);
            }
        }, self::REMOTE_IMPORT_CONCURRENCY);
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
}
