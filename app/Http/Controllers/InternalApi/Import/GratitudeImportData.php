<?php

namespace App\Http\Controllers\InternalApi\Import;

use App\Http\Controllers\Controller;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeLevel;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GratitudeImportData extends Controller
{

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

    public function gratitudeAccountsImports($status = null)
    {
        if ($status) {
            $importStatus = $this->normalizeImportStatus($status);
            $baseUrl = rtrim((string) config('services.aivteam.base_url'), '/');
            $gratitudesUrl = $baseUrl . '/api/gratitude/get/gratitude-data-all-by-status/gratitude/' . $importStatus;
            $getResponse = $this->aivteamHttp()->get($gratitudesUrl);

            dd($getResponse->json());

            $gratitude_import = $this->importGratitudeTable($getResponse->json()['data']['gratitudes']);

            return $gratitude_import;
        } else {

            $baseUrl = rtrim((string) config('services.aivteam.base_url'), '/');
            $gratitudesUrl = $baseUrl . '/api/gratitude/get/gratitude-data-all/gratitude';
            $getResponse = $this->aivteamHttp()->get($gratitudesUrl);
        }
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

            $gratitudeValues['guests_data'] = $this->gratitudeGuests(
                $record['guests'] ?? $record['guests_data'] ?? $record['members'] ?? [],
                $existing
            );


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

    private function normalizeImportStatus(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, ['active', 'inactive'], true)
            ? $status
            : 'active';
    }

    private function defaultLevelName(): string
    {
        return GratitudeLevel::where('status', true)
            ->orderBy('min_points')
            ->value('name') ?? 'Explorer';
    }
}
