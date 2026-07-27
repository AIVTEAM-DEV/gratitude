<?php

namespace Tests\Feature;

use App\Services\Gratitude\AivteamJourneyService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AivteamJourneyServiceTest extends TestCase
{
    public function test_it_fetches_and_normalizes_the_new_journey_endpoint(): void
    {
        config([
            'services.aivteam.base_url' => 'https://aivteam.test',
            'services.aivteam.access_token' => 'test-token',
        ]);

        Http::fake([
            'https://aivteam.test/api/journey/get/all/journeys' => Http::response([
                [
                    'id' => 42,
                    'name' => 'Taste Of Piedmont',
                    'departureDate' => '2026-08-14',
                    'returnDate' => '2026-08-21',
                    'projectNumber' => '123',
                    'projectNumberDisplay' => 'C123',
                ],
            ]),
        ]);

        $journeys = app(AivteamJourneyService::class)->all();

        $this->assertSame([
            [
                'id' => 42,
                'journey_id' => 42,
                'label' => 'C123 - Taste Of Piedmont (2026-08-21)',
                'project_number' => 'C123',
                'name' => 'Taste Of Piedmont',
                'startDate' => '2026-08-14',
                'endDate' => '2026-08-21',
                'source' => 'aivteam',
                'raw' => [
                    'id' => 42,
                    'name' => 'Taste Of Piedmont',
                    'departureDate' => '2026-08-14',
                    'returnDate' => '2026-08-21',
                    'projectNumber' => '123',
                    'projectNumberDisplay' => 'C123',
                ],
            ],
        ], $journeys);

        Http::assertSent(fn ($request) => $request->url() === 'https://aivteam.test/api/journey/get/all/journeys'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_it_returns_null_when_the_journey_endpoint_is_unavailable(): void
    {
        config([
            'services.aivteam.base_url' => 'https://aivteam.test',
            'services.aivteam.access_token' => 'test-token',
        ]);

        Http::fake([
            'https://aivteam.test/api/journey/get/all/journeys' => Http::response([], 503),
        ]);

        $this->assertNull(app(AivteamJourneyService::class)->all());
    }
}
