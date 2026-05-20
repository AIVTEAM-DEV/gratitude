<?php

use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeLevel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

function createGratitudeAccountExportFixture(string $gratitudeNumber): User
{
    config([
        'services.aivteam.base_url' => 'https://aivteam.test',
        'services.aivteam.access_token' => 'test-token',
    ]);

    $user = User::factory()->create();

    GratitudeLevel::create([
        'name' => 'Explorer',
        'min_points' => 0,
        'status' => true,
        'redemption_points_per_dollar' => 25,
        'partner_points_per_dollar' => 25,
    ]);

    Gratitude::create([
        'gratitudeNumber' => $gratitudeNumber,
        'level' => 'Explorer',
        'status' => 'active',
        'is_active' => true,
        'totalPoints' => 1200,
        'totalRemainingPoints' => 900,
        'useablePoints' => 700,
        'totalRedeemedPoints' => 300,
        'totalCancelledPoints' => 50,
        'totalExpiredPoints' => 25,
        'guests_data' => [
            [
                'id' => 101,
                'first_name' => 'Ava',
                'last_name' => 'Primary',
                'preferred_name' => 'A',
                'birthday' => '1991-11-09',
                'email' => 'ava@example.com',
                'ownership' => 'primary',
            ],
            [
                'id' => 102,
                'first_name' => 'Ben',
                'last_name' => 'Guest',
                'preferred_name' => 'Benny',
                'birthday' => '1987-03-12',
                'email' => 'ben@example.com',
                'ownership' => 'secondary',
            ],
        ],
    ]);

    EarnedPoint::create([
        'gratitudeNumber' => $gratitudeNumber,
        'points' => 600,
        'status' => true,
        'usable_date' => Carbon::today(),
        'expires_at' => Carbon::today()->addYear(),
    ]);

    BonusPoint::create([
        'gratitudeNumber' => $gratitudeNumber,
        'points' => 300,
        'status' => true,
        'usable_date' => Carbon::today(),
        'expires_at' => Carbon::today()->addYear(),
    ]);

    Http::fake();

    return $user;
}

test('gratitude account exports group multiple guests under one gratitude number', function (string $format) {
    $gratitudeNumber = 'G-'.strtoupper($format).'-1';
    $user = createGratitudeAccountExportFixture($gratitudeNumber);

    $response = $this->actingAs($user)->get("/internal-api/gratitude/accounts/export/{$format}");

    $response->assertOk();

    if ($format === 'pdf') {
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    $content = $response->getContent();

    expect($content)
        ->toContain('Gratitude - Art In Voyage')
        ->toContain('Ownership')
        ->toContain('Guests First Name')
        ->toContain('Guests Last Name')
        ->toContain('Guests Preferred Name')
        ->toContain('Guests Date of Birth')
        ->toContain('Guests Email')
        ->toContain('Total Balance')
        ->toContain('Useable Points')
        ->toContain('$ Value')
        ->toContain('Ava')
        ->toContain('Primary')
        ->toContain('Secondary')
        ->toContain('November 09, 1991')
        ->toContain('ava@example.com')
        ->toContain('Ben')
        ->toContain('Guest')
        ->toContain('March 12, 1987')
        ->toContain('ben@example.com')
        ->toContain('900,00')
        ->toContain('700,00')
        ->toContain('28,00')
        ->toContain('Active')
        ->not->toContain('Guest Name')
        ->not->toContain('Guest Email')
        ->not->toContain('Primary Guest')
        ->not->toContain('Total Points')
        ->not->toContain('Cancelled')
        ->not->toContain('Expired')
        ->not->toContain('Last Activity');

    if ($format === 'pdf') {
        expect($content)
            ->not->toContain('(Total)')
            ->not->toContain('(Cancelled)')
            ->not->toContain('(Expired)');
    } else {
        expect($content)->toContain('rowspan="2"');
    }

    expect(substr_count($content, $gratitudeNumber))->toBe(1);
    expect(strpos($content, 'Level'))->toBeLessThan(strpos($content, 'Ownership'));
    expect(strpos($content, 'Ownership'))->toBeLessThan(strpos($content, 'Guests First Name'));

    Http::assertNothingSent();
})->with(['pdf', 'excel', 'print']);

test('gratitude account filters can find points about to expire and expiry date ranges', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

    try {
        $user = User::factory()->create();

        Gratitude::create([
            'gratitudeNumber' => 'G-SOON',
            'level' => 'Explorer',
            'status' => 'active',
            'is_active' => true,
            'totalRemainingPoints' => 150,
        ]);

        Gratitude::create([
            'gratitudeNumber' => 'G-LATER',
            'level' => 'Explorer',
            'status' => 'active',
            'is_active' => true,
            'totalRemainingPoints' => 250,
        ]);

        EarnedPoint::create([
            'gratitudeNumber' => 'G-SOON',
            'points' => 150,
            'status' => true,
            'usable_date' => Carbon::today(),
            'expires_at' => Carbon::today()->addDays(10),
        ]);

        BonusPoint::create([
            'gratitudeNumber' => 'G-LATER',
            'points' => 250,
            'status' => true,
            'usable_date' => Carbon::today(),
            'expires_at' => Carbon::today()->addDays(45),
        ]);

        $aboutToExpire = $this->actingAs($user)->getJson('/internal-api/gratitude?expiry_status=about_to_expire');

        $aboutToExpire
            ->assertOk()
            ->assertJsonCount(1, 'points')
            ->assertJsonPath('points.0.gratitudeNumber', 'G-SOON')
            ->assertJsonPath('points.0.total_balance', 150);

        $dateRange = $this->actingAs($user)->getJson('/internal-api/gratitude?expires_from=2026-07-01&expires_to=2026-07-15');

        $dateRange
            ->assertOk()
            ->assertJsonCount(1, 'points')
            ->assertJsonPath('points.0.gratitudeNumber', 'G-LATER')
            ->assertJsonPath('points.0.total_balance', 250);
    } finally {
        Carbon::setTestNow();
    }
});

test('gratitude account total balance is the remaining points while pending points stay separate', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

    try {
        $user = User::factory()->create();

        Gratitude::create([
            'gratitudeNumber' => 'G-PENDING-BALANCE',
            'level' => 'Explorer',
            'status' => 'active',
            'is_active' => true,
            'totalRemainingPoints' => 350,
            'useablePoints' => 100,
        ]);

        EarnedPoint::create([
            'gratitudeNumber' => 'G-PENDING-BALANCE',
            'points' => 100,
            'status' => true,
            'usable_date' => Carbon::today(),
            'expires_at' => Carbon::today()->addYear(),
        ]);

        EarnedPoint::create([
            'gratitudeNumber' => 'G-PENDING-BALANCE',
            'points' => 250,
            'status' => true,
            'usable_date' => Carbon::today()->addDays(14),
            'expires_at' => null,
        ]);

        $response = $this->actingAs($user)->getJson('/internal-api/gratitude?search=G-PENDING-BALANCE');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'points')
            ->assertJsonPath('points.0.total_balance', 350)
            ->assertJsonPath('points.0.useablePoints', 100)
            ->assertJsonPath('points.0.pending_points', 250);
    } finally {
        Carbon::setTestNow();
    }
});
