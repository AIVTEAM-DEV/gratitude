<?php

namespace App\Services\Gratitude;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AivteamJourneyService
{
    public function all(): ?array
    {
        $url = rtrim((string) config('services.aivteam.base_url'), '/')
            .'/api/journey/get/all/journeys';

        try {
            $request = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(15);

            $accessToken = config('services.aivteam.access_token');

            if (is_string($accessToken) && $accessToken !== '') {
                $request->withToken($accessToken);
            }

            $response = $request->get($url);
        } catch (\Throwable $exception) {
            Log::warning('Unable to retrieve the AIV Team journey list.', [
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('The AIV Team journey list request failed.', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        return collect($this->journeyRows($response->json()))
            ->filter(fn ($journey) => is_array($journey) && ! empty($journey['id']))
            ->map(fn (array $journey) => $this->normalize($journey))
            ->sortBy([
                ['returnDate', 'desc'],
                ['label', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function journeyRows(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['data', 'journeys'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_is_list($payload[$key])
                    ? $payload[$key]
                    : array_values($payload[$key]);
            }
        }

        return [];
    }

    private function normalize(array $journey): array
    {
        $journeyId = $journey['id'];
        $projectNumber = $journey['projectNumberDisplay']
            ?? $journey['projectNumber']
            ?? null;
        $name = $journey['name'] ?? null;
        $returnDate = $journey['returnDate'] ?? null;
        $title = trim(implode(' - ', array_filter([$projectNumber, $name])));

        if ($title === '') {
            $title = 'Journey #'.$journeyId;
        }

        return array_filter([
            'id' => $journeyId,
            'journey_id' => $journeyId,
            'label' => $returnDate ? "{$title} ({$returnDate})" : $title,
            'project_number' => $projectNumber,
            'name' => $name,
            'startDate' => $journey['departureDate'] ?? null,
            'endDate' => $returnDate,
            'source' => 'aivteam',
            'raw' => $journey,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
