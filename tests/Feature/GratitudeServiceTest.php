<?php

namespace Tests\Feature;

use App\Http\Middleware\ValidateBearerToken;
use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\Cancellation;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeBenefit;
use App\Models\Gratitude\GratitudeEarnedBenefit;
use App\Models\Gratitude\GratitudeLevel;
use App\Models\Gratitude\RedeemPoints;
use App\Models\Gratitude\RedeemPointsDetails;
use App\Models\User;
use App\Services\Gratitude\BonusPointService;
use App\Services\Gratitude\CancellationService;
use App\Services\Gratitude\EarnedPointService;
use App\Services\Gratitude\GratitudeService;
use App\Services\Gratitude\PointService;
use App\Services\Gratitude\TierService;
use Carbon\Carbon;
use Database\Seeders\GratitudeLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GratitudeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $pointService;

    protected $tierService;

    protected $gratitudeService;

    protected $gratitudeNumber = 'G-TEST-1001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GratitudeLevelSeeder::class);
        $this->user = User::factory()->create();
        $developerRole = Role::firstOrCreate(['name' => 'Developer', 'guard_name' => 'web']);
        $this->user->assignRole($developerRole);
        $this->pointService = app(PointService::class);
        $this->tierService = new TierService;
        $this->gratitudeService = app(GratitudeService::class);

        Gratitude::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'totalPoints' => 0,
            'useablePoints' => 0,
            'level' => 'Explorer',
            'level_obtained_at' => Carbon::today(),
            'systemLevelUpdate' => true,
        ]);
    }

    public function test_internal_imports_require_developer_role()
    {
        $this->user->syncRoles([]);

        $this->actingAs($this->user)
            ->getJson('/internal-api/gratitude/migrate-data/active')
            ->assertForbidden();

        $this->actingAs($this->user)
            ->getJson('/internal-api/gratitude/migrate-account-data/active')
            ->assertForbidden();

        $this->actingAs($this->user)
            ->postJson('/internal-api/gratitude/account/G0007/import')
            ->assertForbidden();

        $this->actingAs($this->user)
            ->getJson('/internal-api/gratitude/migrate-benefits/data')
            ->assertForbidden();
    }

    public function test_create_account_generates_the_next_gratitude_number()
    {
        Gratitude::create([
            'gratitudeNumber' => 'G0009',
            'level' => 'Explorer',
            'level_obtained_at' => Carbon::today(),
        ]);

        $gratitude = $this->gratitudeService->createAccount();

        $this->assertEquals('G0010', $gratitude->gratitudeNumber);
        $this->assertEquals('Explorer', $gratitude->level);
        $this->assertEquals(0, $gratitude->totalPoints);
        $this->assertTrue($gratitude->is_active);

        $manualLevelAttempt = $this->gratitudeService->createAccount([
            'gratitudeNumber' => 'G0011',
            'level' => 'Jetsetter',
        ]);

        $this->assertEquals('Explorer', $manualLevelAttempt->level);
    }

    public function test_external_api_can_create_a_gratitude_account()
    {
        $this->withoutMiddleware(ValidateBearerToken::class);

        $response = $this->postJson('/api/v1/gratitude', [
            'gratitude_number' => 'G-EXT-1001',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Gratitude account created')
            ->assertJsonPath('gratitude.gratitudeNumber', 'G-EXT-1001');

        $this->assertDatabaseHas('gratitudes', [
            'gratitudeNumber' => 'G-EXT-1001',
            'level' => 'Explorer',
        ]);
    }

    public function test_external_api_can_check_balance()
    {
        $this->withoutMiddleware(ValidateBearerToken::class);

        $gratitude = Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->firstOrFail();
        $gratitude->update([
            'totalPoints' => 1250,
            'totalEarnedPoints' => 1000,
            'totalBonusPoints' => 250,
            'totalRemainingPoints' => 900,
            'useablePoints' => 800,
            'nonUseablePoints' => 450,
            'totalRedeemedPoints' => 300,
            'totalCancelledPoints' => 50,
        ]);

        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'date' => Carbon::today(),
            'points' => 100,
            'status' => 'pending',
            'usable_date' => Carbon::today()->addDay(),
        ]);

        $response = $this->getJson("/api/v1/gratitude/{$this->gratitudeNumber}/balance");

        $response
            ->assertOk()
            ->assertJsonPath('gratitudeNumber', $this->gratitudeNumber)
            ->assertJsonPath('balance.total_points', 1250)
            ->assertJsonPath('balance.usable_points', 800)
            ->assertJsonPath('balance.pending_points', 100);
    }

    public function test_external_api_all_includes_usable_points_dollar_value()
    {
        $this->withoutMiddleware(ValidateBearerToken::class);

        Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->update([
            'useablePoints' => 70,
            'level' => 'Explorer',
        ]);

        $response = $this->getJson('/api/v1/gratitude/all');

        $response
            ->assertOk()
            ->assertJsonPath('0.gratitudeNumber', $this->gratitudeNumber)
            ->assertJsonPath('0.usable_points', 70)
            ->assertJsonPath('0.points_per_dollar', 35)
            ->assertJsonPath('0.usable_points_dollar_value', 2);
    }

    public function test_internal_overview_includes_usable_points_total_amount()
    {
        GratitudeLevel::where('name', 'Explorer')->update([
            'redemption_points_per_dollar' => 35,
        ]);
        GratitudeLevel::where('name', 'Globetrotter')->update([
            'redemption_points_per_dollar' => 30,
        ]);

        Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->update([
            'totalPoints' => 70,
            'useablePoints' => 70,
            'level' => 'Explorer',
        ]);

        Gratitude::create([
            'gratitudeNumber' => 'G-OVERVIEW-2001',
            'totalPoints' => 60,
            'useablePoints' => 60,
            'level' => 'Globetrotter',
            'level_obtained_at' => Carbon::today(),
        ]);

        Gratitude::create([
            'gratitudeNumber' => 'G-OVERVIEW-INACTIVE',
            'totalPoints' => 900,
            'useablePoints' => 900,
            'level' => 'Explorer',
            'status' => 'inactive',
            'is_active' => false,
            'level_obtained_at' => Carbon::today(),
        ]);

        EarnedPoint::create([
            'gratitudeNumber' => 'G-OVERVIEW-2001',
            'date' => Carbon::today(),
            'usable_date' => Carbon::tomorrow(),
            'points' => 25,
            'status' => true,
        ]);

        EarnedPoint::create([
            'gratitudeNumber' => 'G-OVERVIEW-INACTIVE',
            'date' => Carbon::today(),
            'usable_date' => Carbon::tomorrow(),
            'points' => 500,
            'status' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson('/internal-api/gratitude/overview');

        $response
            ->assertOk()
            ->assertJsonPath('total_accounts', 2)
            ->assertJsonPath('total_point_balance', 130)
            ->assertJsonPath('total_usable_points', 130)
            ->assertJsonPath('total_pending_points', 25);

        $this->assertEquals(4, $response->json('total_usable_amount'));
    }

    public function test_external_api_can_check_level()
    {
        $this->withoutMiddleware(ValidateBearerToken::class);

        $response = $this->getJson("/api/v1/gratitude/{$this->gratitudeNumber}/level");

        $response
            ->assertOk()
            ->assertJsonPath('gratitudeNumber', $this->gratitudeNumber)
            ->assertJsonPath('level.name', 'Explorer')
            ->assertJsonPath('level.system_level_update', true)
            ->assertJsonPath('level_rules.min_points', 0);
    }

    public function test_external_api_can_get_benefits_for_a_level()
    {
        $this->withoutMiddleware(ValidateBearerToken::class);

        $level = GratitudeLevel::where('name', 'Explorer')->firstOrFail();
        $benefit = GratitudeBenefit::create([
            'name' => 'Late Checkout',
            'benefit_key' => 'late_checkout',
            'description' => 'Late checkout benefit',
            'type' => 'journey',
            'is_active' => true,
        ]);

        $level->benefits()->attach($benefit->id, [
            'description' => 'Explorer late checkout',
            'value' => 'Included',
            'value_type' => 'text',
            'is_active' => true,
            'web_status' => true,
        ]);

        $response = $this->getJson('/api/v1/gratitude/levels/Explorer/benefits');

        $response
            ->assertOk()
            ->assertJsonPath('level.name', 'Explorer')
            ->assertJsonPath('benefits.0.benefit_key', 'late_checkout')
            ->assertJsonPath('benefits.0.value', 'Included');
    }

    public function test_external_api_can_get_points_history()
    {
        $this->withoutMiddleware(ValidateBearerToken::class);

        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 1101,
            'date' => Carbon::today(),
            'usable_date' => Carbon::today(),
            'points' => 1200,
            'status' => 'active',
            'description' => 'Journey completed',
        ]);

        BonusPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'date' => Carbon::today()->subDay(),
            'points' => 300,
            'status' => true,
            'description' => 'Service recovery bonus',
        ]);

        $response = $this->getJson("/api/v1/gratitude/{$this->gratitudeNumber}/points-history");

        $response
            ->assertOk()
            ->assertJsonPath('gratitudeNumber', $this->gratitudeNumber)
            ->assertJsonPath('history.0.type', 'earned')
            ->assertJsonPath('history.0.points', 1200)
            ->assertJsonPath('history.1.type', 'bonus');
    }

    public function test_external_api_single_includes_points_history_and_earned_benefits()
    {
        $this->withoutMiddleware(ValidateBearerToken::class);

        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 1201,
            'date' => Carbon::today(),
            'usable_date' => Carbon::today(),
            'points' => 900,
            'status' => 'active',
            'description' => 'Journey completed',
        ]);

        $benefit = GratitudeBenefit::create([
            'name' => 'Late Checkout',
            'benefit_key' => 'late_checkout',
            'description' => 'Late checkout benefit',
            'type' => 'journey',
            'is_active' => true,
        ]);

        GratitudeEarnedBenefit::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'benefit_id' => $benefit->id,
            'benefit_name' => 'Late Checkout',
            'benefit_key' => 'late_checkout',
            'description' => 'Late checkout granted',
            'benefit_value' => '2 Hours',
            'value_type' => 'item',
            'date' => Carbon::today(),
            'status' => 'used',
        ]);

        $response = $this->getJson("/api/v1/gratitude/{$this->gratitudeNumber}");

        $response
            ->assertOk()
            ->assertJsonPath('gratitude.gratitudeNumber', $this->gratitudeNumber)
            ->assertJsonPath('points_history.0.type', 'earned')
            ->assertJsonPath('points_history.0.points', 900)
            ->assertJsonPath('earned_benefits.0.benefit_name', 'Late Checkout')
            ->assertJsonPath('earned_benefits.0.benefit.benefit_key', 'late_checkout');
    }

    public function test_pending_points_are_activated_on_usable_date()
    {
        // Add pending points usable in the future
        $this->pointService->addTierPoints($this->gratitudeNumber, 1000, Carbon::today()->addDays(5), 501);

        $activated = $this->pointService->activateTierPoints();
        $this->assertEquals(0, $activated); // Should not activate

        // Add points usable today
        $this->pointService->addTierPoints($this->gratitudeNumber, 2000, Carbon::today(), 502);

        $activatedNow = $this->pointService->activateTierPoints();
        $this->assertEquals(1, $activatedNow); // Should activate the one usable today

        $point = EarnedPoint::where('points', 2000)->first();
        $this->assertEquals('active', $point->status);
        $this->assertNotNull($point->expires_at);
    }

    public function test_fifo_point_redemption()
    {
        // Create 3 batches of points
        $this->pointService->addBonusPoints($this->gratitudeNumber, 500); // Expires in 2 years

        $p2 = new EarnedPoint(['gratitudeNumber' => $this->gratitudeNumber, 'journey_id' => 601, 'date' => Carbon::today(), 'usable_date' => Carbon::today(), 'points' => 1000, 'status' => 'active', 'expires_at' => Carbon::today()->addDays(30)]);
        $p2->save();

        $p3 = new EarnedPoint(['gratitudeNumber' => $this->gratitudeNumber, 'journey_id' => 602, 'date' => Carbon::today(), 'usable_date' => Carbon::today(), 'points' => 800, 'status' => 'active', 'expires_at' => Carbon::today()->addDays(10)]);
        $p3->save();

        // Points available: 500 (2 yrs), 1000 (30 days), 800 (10 days)
        // Order of expiration: p3 (10 days) -> p2 (30 days) -> Bonus (2 yrs)

        // Attempt to redeem 1000 points
        $result = $this->pointService->redeemPoints($this->gratitudeNumber, 1000, 'Hotel stay');
        $this->assertNotNull($result);

        $p3->refresh();
        $p2->refresh();

        // p3 should be completely drained (800 redeemed)
        $this->assertEquals(800, $p3->redeemed_points);
        $this->assertEquals(0, $p3->remaining_points);

        // p2 should have 200 redeemed
        $this->assertEquals(200, $p2->redeemed_points);
        $this->assertEquals(800, $p2->remaining_points);
    }

    public function test_tier_upgrades_and_waits_until_interval_expiry_to_downgrade()
    {
        // Initially Explorer
        $gratitude = $this->tierService->recalculateTier($this->gratitudeNumber);
        $this->assertEquals('Explorer', $gratitude->level);

        // Add 16000 usable tier points (within 2 years) -> Globetrotter
        $p1 = EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 701,
            'date' => Carbon::today()->subDays(7),
            'points' => 16000,
            'status' => 'active',
            'usable_date' => Carbon::today(),
        ]);

        $gratitude = $this->tierService->recalculateTier($this->gratitudeNumber);
        $this->assertEquals('Globetrotter', $gratitude->level);
        $this->assertEquals('upgrade', $gratitude->statusChange);
        $this->assertEquals(Carbon::today()->toDateString(), $gratitude->level_obtained_at->toDateString());

        $this->gratitudeService->redeemPoints($this->gratitudeNumber, ['reason' => 'Partner spend', 'redemption_type' => 'partner'], 15000);
        $gratitude = $this->tierService->recalculateTier($this->gratitudeNumber);
        $this->assertEquals('Globetrotter', $gratitude->level, 'Redeeming points must not downgrade the level inside the 2-year interval.');

        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 702,
            'date' => Carbon::today()->addDay(),
            'points' => 30001,
            'status' => 'active',
            'usable_date' => Carbon::today()->addDay(),
        ]);

        $gratitude = $this->tierService->recalculateTier($this->gratitudeNumber, 'system', Carbon::today()->addDay());
        $this->assertEquals('Jetsetter', $gratitude->level);
        $this->assertEquals(Carbon::today()->addDay()->toDateString(), $gratitude->level_obtained_at->toDateString());

        $p1->usable_date = Carbon::today()->subYears(3);
        $p1->save();
        $gratitude->update(['level_obtained_at' => Carbon::today()->subYears(2)->subDays(3)]);

        $gratitude = $this->tierService->recalculateTier($this->gratitudeNumber);
        $this->assertEquals('Explorer', $gratitude->level);
        $this->assertEquals('downgrade', $gratitude->statusChange);
    }

    public function test_tier_recalculation_uses_remaining_points_after_tomorrow_when_earned_points_are_expiring_soon()
    {
        $asOf = Carbon::parse('2026-05-26 10:00:00');
        $cycleStart = $asOf->copy()->subYear()->startOfDay();
        $gratitude = Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->firstOrFail();

        $gratitude->update([
            'level' => 'Globetrotter',
            'level_obtained_at' => $cycleStart,
            'systemLevelUpdate' => true,
            'levelHistory' => [
                [
                    'fromLevel' => 'Explorer',
                    'toLevel' => 'Globetrotter',
                    'changeType' => 'upgrade',
                    'date' => $cycleStart->toDateString(),
                    'earnedPoints' => 16000,
                    'journeyCount' => 1,
                    'changedBy' => 'system',
                    'reason' => 'Test setup',
                ],
            ],
        ]);

        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 1705,
            'date' => $cycleStart->copy()->addMonth(),
            'usable_date' => $cycleStart->copy()->addMonth(),
            'points' => 16000,
            'status' => true,
            'expires_at' => $asOf->copy()->addDay()->endOfDay(),
        ]);

        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 1706,
            'date' => $cycleStart->copy()->addMonths(2),
            'usable_date' => $cycleStart->copy()->addMonths(2),
            'points' => 5000,
            'status' => true,
            'expires_at' => $asOf->copy()->addMonth(),
        ]);

        $updated = $this->tierService->recalculateTier($this->gratitudeNumber, 'system', $asOf);
        $latestHistory = collect($updated->levelHistory)->last();

        $this->assertEquals('Explorer', $updated->level);
        $this->assertEquals('downgrade', $updated->statusChange);
        $this->assertEquals(5000, $latestHistory['earnedPoints']);
        $this->assertStringContainsString('remaining eligible earned points', $updated->statusChangeReason);
    }

    public function test_tier_upgrade_uses_qualification_date_even_when_recalculated_after_cycle_end()
    {
        $gratitude = Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->firstOrFail();
        $gratitude->update([
            'level' => 'Explorer',
            'levelHistory' => null,
            'level_obtained_at' => Carbon::parse('2024-01-01'),
            'systemLevelUpdate' => true,
        ]);

        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 1701,
            'date' => '2024-05-25',
            'points' => 16000,
            'status' => true,
            'usable_date' => '2024-06-01',
        ]);

        $updated = $this->tierService->recalculateTier(
            $this->gratitudeNumber,
            'system',
            Carbon::parse('2026-01-04')
        );

        $this->assertEquals('Globetrotter', $updated->level);
        $this->assertEquals('upgrade', $updated->statusChange);
        $this->assertEquals('2024-06-01', $updated->level_obtained_at->toDateString());
        $this->assertEquals('2024-06-01', collect($updated->levelHistory)->last()['date']);
    }

    public function test_manual_level_changes_are_kept_until_import_resets_system_management()
    {
        $gratitude = Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->firstOrFail();

        $manual = $this->tierService->setLevelManually($gratitude, 'Globetrotter', 'admin', 'Manual correction');

        $this->assertFalse($manual->systemLevelUpdate);

        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 1702,
            'date' => Carbon::today(),
            'points' => 16000,
            'status' => true,
            'usable_date' => Carbon::today(),
        ]);
        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 1703,
            'date' => Carbon::today(),
            'points' => 15001,
            'status' => true,
            'usable_date' => Carbon::today(),
        ]);

        $updated = $this->tierService->recalculateTier($this->gratitudeNumber);

        $this->assertEquals('Globetrotter', $updated->level);
        $this->assertFalse($updated->systemLevelUpdate);

        $updated->forceFill(['old_id' => 1704])->save();

        $this->gratitudeService->import([
            [
                'id' => 1704,
                'gratitudeNumber' => $this->gratitudeNumber,
                'status' => 'active',
            ],
        ]);

        $updated->refresh();

        $this->assertTrue($updated->systemLevelUpdate);
    }

    public function test_manual_level_override_returns_to_system_management_after_configured_cycle_plus_grace_days()
    {
        $gratitude = Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));

        try {
            $manual = $this->tierService->setLevelManually($gratitude, 'Globetrotter', 'admin', 'Manual correction');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertFalse($manual->systemLevelUpdate);
        $this->assertEquals('2026-01-01', $manual->level_obtained_at->toDateString());

        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 1710,
            'date' => '2026-01-10',
            'points' => 16000,
            'status' => true,
            'usable_date' => '2026-01-10',
        ]);
        EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 1711,
            'date' => '2026-02-01',
            'points' => 15001,
            'status' => true,
            'usable_date' => '2026-02-01',
        ]);

        $beforeCycleEnd = $this->tierService->recalculateTier(
            $this->gratitudeNumber,
            'scheduled_cycle_check',
            Carbon::parse('2027-12-31')
        );

        $this->assertFalse($beforeCycleEnd->systemLevelUpdate);
        $this->assertEquals('Globetrotter', $beforeCycleEnd->level);

        $duringGrace = $this->tierService->recalculateTier(
            $this->gratitudeNumber,
            'scheduled_cycle_check',
            Carbon::parse('2028-01-02')
        );

        $this->assertFalse($duringGrace->systemLevelUpdate);
        $this->assertEquals('Globetrotter', $duringGrace->level);

        $afterCycleEnd = $this->tierService->recalculateTier(
            $this->gratitudeNumber,
            'scheduled_cycle_check',
            Carbon::parse('2028-01-04')
        );

        $this->assertTrue($afterCycleEnd->systemLevelUpdate);
        $this->assertEquals('Jetsetter', $afterCycleEnd->level);
        $this->assertEquals('upgrade', $afterCycleEnd->statusChange);
        $this->assertEquals('2026-02-01', $afterCycleEnd->level_obtained_at->toDateString());
    }

    public function test_manual_level_override_cycle_restarts_when_changed_manually_again()
    {
        $gratitude = Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->firstOrFail();

        try {
            Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));
            $this->tierService->setLevelManually($gratitude, 'Globetrotter', 'admin', 'Manual correction');

            Carbon::setTestNow(Carbon::parse('2027-06-01 09:00:00'));
            $manual = $this->tierService->setLevelManually($gratitude->fresh(), 'Explorer', 'admin', 'Manual renewal');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertFalse($manual->systemLevelUpdate);
        $this->assertEquals('Explorer', $manual->level);
        $this->assertEquals('2027-06-01', $manual->level_obtained_at->toDateString());

        $reviewed = $this->tierService->recalculateTier(
            $this->gratitudeNumber,
            'scheduled_cycle_check',
            Carbon::parse('2028-01-02')
        );

        $this->assertFalse($reviewed->systemLevelUpdate);
        $this->assertEquals('Explorer', $reviewed->level);
    }

    public function test_partial_cancellation_only_removes_remaining_points()
    {
        $point = EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'journey_id' => 801,
            'date' => Carbon::today(),
            'usable_date' => Carbon::today(),
            'points' => 1000,
            'redeemed_points' => 400,
            'status' => 'active',
            'expires_at' => Carbon::today()->addYear(),
        ]);

        app(CancellationService::class)->cancel(
            Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->first(),
            [
                'date' => Carbon::today()->toDateString(),
                'cancellation_reason' => 'Journey adjustment',
                'cancellation_points' => 250,
            ],
            $point->id,
        );

        $point->refresh();

        $this->assertEquals(250, $point->cancelled_points);
        $this->assertEquals(350, $point->remaining_points);
        $this->assertNull($point->cancel_id);
    }

    public function test_updating_cancelled_earned_point_removes_cancellation_first()
    {
        $gratitude = Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->firstOrFail();
        $point = EarnedPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'date' => Carbon::parse('2026-01-01'),
            'usable_date' => Carbon::parse('2026-01-01'),
            'points' => 100,
            'amount' => 100,
            'category' => 'manual',
            'description' => 'Original earned entry',
            'status' => 'active',
        ]);

        app(CancellationService::class)->cancel($gratitude, [
            'date' => '2026-01-05',
            'cancellation_reason' => 'Original cancellation',
            'cancellation_points' => 100,
        ], $point->id);

        $point->refresh();
        $this->assertEquals(100, $point->cancelled_points);
        $this->assertNotNull($point->cancel_id);

        $updated = app(EarnedPointService::class)->update($point, $gratitude, [
            'earning_type' => 'other',
            'date' => '2026-02-10',
            'category' => 'manual_adjustment',
            'points' => 250,
            'amount' => 250,
            'description' => 'Updated earned entry',
        ]);

        $this->assertNull($updated->cancel_id);
        $this->assertEquals(0, $updated->cancelled_points);
        $this->assertEquals(250, $updated->points);
        $this->assertEquals('2026-02-10', $updated->date->toDateString());
        $this->assertDatabaseMissing('cancellations', [
            'gratitudeNumber' => $this->gratitudeNumber,
            'description' => 'Original cancellation',
        ]);
    }

    public function test_updating_cancelled_bonus_point_removes_cancellation_first()
    {
        $gratitude = Gratitude::where('gratitudeNumber', $this->gratitudeNumber)->firstOrFail();
        $point = BonusPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'date' => Carbon::parse('2026-01-01'),
            'usable_date' => Carbon::parse('2026-01-01'),
            'points' => 100,
            'amount' => 100,
            'category' => 'manual',
            'type' => 'other',
            'description' => 'Original bonus entry',
            'status' => true,
        ]);

        app(CancellationService::class)->cancel($gratitude, [
            'date' => '2026-01-05',
            'cancellation_reason' => 'Original bonus cancellation',
            'cancellation_points' => 100,
        ], null, $point->id);

        $point->refresh();
        $this->assertEquals(100, $point->cancelled_points);
        $this->assertNotNull($point->cancel_id);

        $updated = app(BonusPointService::class)->update($point, $gratitude, [
            'type' => 'other',
            'date' => '2026-03-12',
            'category' => 'service_bonus',
            'points' => 300,
            'amount' => 300,
            'description' => 'Updated bonus entry',
        ]);

        $this->assertNull($updated->cancel_id);
        $this->assertEquals(0, $updated->cancelled_points);
        $this->assertEquals(300, $updated->points);
        $this->assertEquals('2026-03-12', $updated->date->toDateString());
        $this->assertDatabaseMissing('cancellations', [
            'gratitudeNumber' => $this->gratitudeNumber,
            'description' => 'Original bonus cancellation',
        ]);
    }

    public function test_partner_redemption_uses_partner_points_per_dollar_rate()
    {
        GratitudeLevel::where('name', 'Explorer')->update([
            'redemption_points_per_dollar' => 35,
            'partner_points_per_dollar' => 50,
        ]);

        BonusPoint::create([
            'gratitudeNumber' => $this->gratitudeNumber,
            'date' => Carbon::today(),
            'points' => 100,
            'status' => true,
            'description' => 'Partner spend balance',
            'expires_at' => Carbon::today()->addYear(),
        ]);

        $redemption = $this->gratitudeService->redeemPoints($this->gratitudeNumber, [
            'reason' => 'Partner purchase',
            'redemption_type' => 'partner',
        ], 50);

        $this->assertEquals('partner', $redemption->category);
        $this->assertEquals('1.00', (string) $redemption->amount);
        $this->assertEquals(50, $redemption->points_breakdown['points_per_dollar']);
    }

    public function test_redeem_points_consumes_soonest_expiring_points_first()
    {
        $gratitudeNumber = 'G-1001';

        Gratitude::create([
            'gratitudeNumber' => $gratitudeNumber,
            'totalPoints' => 800,
            'useablePoints' => 800,
            'level' => 'Explorer',
            'level_obtained_at' => Carbon::today(),
        ]);

        $bonus = BonusPoint::create([
            'user_id' => $this->user->id,
            'gratitudeNumber' => $gratitudeNumber,
            'date' => Carbon::parse('2026-03-01'),
            'points' => 300,
            'status' => true,
            'description' => 'Bonus batch',
            'expires_at' => Carbon::today()->addDays(15)->endOfDay(),
        ]);

        $earned = EarnedPoint::create([
            'user_id' => $this->user->id,
            'gratitudeNumber' => $gratitudeNumber,
            'journey_id' => 901,
            'date' => Carbon::parse('2026-03-01'),
            'usable_date' => Carbon::parse('2026-03-01'),
            'points' => 500,
            'status' => 'active',
            'description' => 'Earned batch',
            'expires_at' => Carbon::today()->addDays(15)->startOfDay(),
        ]);

        $redemption = $this->gratitudeService->redeemPoints($gratitudeNumber, ['reason' => 'Test redeem'], 350);

        $this->assertNotFalse($redemption);

        $bonus->refresh();
        $earned->refresh();

        $this->assertEquals(0, $bonus->redeemed_points);
        $this->assertEquals(350, $earned->redeemed_points);
    }

    public function test_redemption_uses_supplied_redemption_date_for_allocation_and_history()
    {
        $gratitudeNumber = 'G-REDEEM-DATE';

        Carbon::setTestNow(Carbon::parse('2026-04-01 12:00:00'));

        try {
            Gratitude::create([
                'gratitudeNumber' => $gratitudeNumber,
                'level' => 'Explorer',
                'level_obtained_at' => Carbon::parse('2026-01-01'),
            ]);

            $firstPoint = EarnedPoint::create([
                'gratitudeNumber' => $gratitudeNumber,
                'date' => Carbon::parse('2026-01-01'),
                'usable_date' => Carbon::parse('2026-01-01'),
                'points' => 500,
                'status' => 'active',
                'description' => 'First available batch',
                'expires_at' => Carbon::parse('2026-12-31'),
            ]);

            $futurePoint = BonusPoint::create([
                'gratitudeNumber' => $gratitudeNumber,
                'date' => Carbon::parse('2026-03-01'),
                'usable_date' => Carbon::parse('2026-03-01'),
                'points' => 500,
                'status' => true,
                'description' => 'Future bonus batch',
                'expires_at' => Carbon::parse('2026-12-31'),
            ]);

            $redemption = $this->gratitudeService->redeemPoints($gratitudeNumber, [
                'date' => '2026-02-01',
                'reason' => 'Historical redemption',
                'redemption_type' => 'partner',
            ], 400);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertNotFalse($redemption);

        $redemption->refresh();
        $firstPoint->refresh();
        $futurePoint->refresh();
        $detail = RedeemPointsDetails::where('redeem_id', $redemption->id)->firstOrFail();

        $this->assertEquals('2026-02-01', $redemption->created_at->toDateString());
        $this->assertEquals('2026-02-01', $redemption->points_breakdown['redemption_date']);
        $this->assertEquals(400, $firstPoint->redeemed_points);
        $this->assertEquals(0, $futurePoint->redeemed_points);
        $this->assertEquals('2026-02-01', $detail->created_at->toDateString());
        $this->assertEquals('2026-02-01', $detail->points_breakdown['date']);
        $this->assertEquals('2026-02-01', $firstPoint->redemption_history[0]['date']);
    }

    public function test_imported_redemptions_are_rebuilt_using_redemption_date_level_and_usable_points()
    {
        $gratitudeNumber = 'G-IMPORT-REDEEM-HISTORY';

        Carbon::setTestNow(Carbon::parse('2026-04-01 12:00:00'));

        try {
            $this->gratitudeService->import([
                [
                    'id' => 993,
                    'gratitudeNumber' => $gratitudeNumber,
                    'level' => 'Jetsetter',
                    'earnedPoints' => [
                        [
                            'id' => 1001,
                            'gratitudeNumber' => $gratitudeNumber,
                            'journey_id' => 3001,
                            'points' => 16000,
                            'redeemed_points' => 0,
                            'date' => '2026-01-01 00:00:00',
                            'description' => 'First journey points',
                            'status' => 'active',
                        ],
                        [
                            'id' => 1002,
                            'gratitudeNumber' => $gratitudeNumber,
                            'journey_id' => 3002,
                            'points' => 15001,
                            'redeemed_points' => 0,
                            'date' => '2026-02-01 00:00:00',
                            'description' => 'Second journey points',
                            'status' => 'active',
                        ],
                    ],
                    'redeemPoints' => [
                        [
                            'id' => 2001,
                            'gratitudeNumber' => $gratitudeNumber,
                            'points' => 3000,
                            'amount' => 999,
                            'category' => 'partner',
                            'description' => 'Imported historical partner redemption',
                            'status' => 'approved',
                            'created_at' => '2026-02-15 09:00:00',
                        ],
                    ],
                ],
            ], [
                3001 => ['id' => 3001, 'endDate' => '2026-01-10'],
                3002 => ['id' => 3002, 'endDate' => '2026-03-01'],
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $redemption = RedeemPoints::with('details.source')
            ->where('gratitudeNumber', $gratitudeNumber)
            ->firstOrFail();
        $firstPoint = EarnedPoint::where('old_id', 1001)->firstOrFail();
        $secondPoint = EarnedPoint::where('old_id', 1002)->firstOrFail();

        $this->assertEquals('Jetsetter', $gratitude->level);
        $this->assertEquals(3000, $firstPoint->redeemed_points);
        $this->assertEquals(0, $secondPoint->redeemed_points);
        $this->assertEquals('85.71', (string) $redemption->amount);
        $this->assertEquals('Globetrotter', $redemption->points_breakdown['level_at_redemption']);
        $this->assertEquals(35, $redemption->points_breakdown['points_per_dollar']);
        $this->assertEquals(16000, $redemption->points_breakdown['usable_points_at_redemption']);
        $this->assertEquals(0, $redemption->points_breakdown['unallocated_points']);
        $this->assertCount(1, $redemption->details);
        $this->assertEquals($firstPoint->id, $redemption->details->first()->source_id);
    }

    public function test_imported_redemptions_use_original_date_field_instead_of_import_date()
    {
        $gratitudeNumber = 'G-IMPORT-REDEEM-DATE-FIELD';

        Carbon::setTestNow(Carbon::parse('2026-04-01 12:00:00'));

        try {
            $this->gratitudeService->import([
                [
                    'id' => 994,
                    'gratitudeNumber' => $gratitudeNumber,
                    'earnedPoints' => [
                        [
                            'id' => 1101,
                            'gratitudeNumber' => $gratitudeNumber,
                            'points' => 500,
                            'redeemed_points' => 0,
                            'date' => '2026-01-01 00:00:00',
                            'description' => 'First usable batch',
                            'status' => 'active',
                        ],
                        [
                            'id' => 1102,
                            'gratitudeNumber' => $gratitudeNumber,
                            'points' => 500,
                            'redeemed_points' => 0,
                            'date' => '2026-03-01 00:00:00',
                            'description' => 'Later usable batch',
                            'status' => 'active',
                        ],
                    ],
                    'redeemPoints' => [
                        [
                            'id' => 2101,
                            'gratitudeNumber' => $gratitudeNumber,
                            'points' => 600,
                            'amount' => 600,
                            'category' => 'partner',
                            'description' => 'Imported redemption with legacy date',
                            'status' => 'approved',
                            'date' => '2026-02-01 10:00:00',
                        ],
                    ],
                ],
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $redemption = RedeemPoints::with('details.source')
            ->where('gratitudeNumber', $gratitudeNumber)
            ->firstOrFail();
        $firstPoint = EarnedPoint::where('old_id', 1101)->firstOrFail();
        $secondPoint = EarnedPoint::where('old_id', 1102)->firstOrFail();

        $this->assertEquals('2026-02-01', $redemption->created_at->toDateString());
        $this->assertEquals('2026-02-01', $redemption->points_breakdown['redemption_date']);
        $this->assertEquals(100, $redemption->points_breakdown['unallocated_points']);
        $this->assertEquals(500, $firstPoint->redeemed_points);
        $this->assertEquals(0, $secondPoint->redeemed_points);
    }

    public function test_imported_legacy_balance_transfer_earned_points_expire_on_december_31_2024()
    {
        $gratitudeNumber = 'G-IMPORT-BALANCE-TRANSFER';

        $this->gratitudeService->import([
            [
                'id' => 986,
                'gratitudeNumber' => $gratitudeNumber,
                'level' => 'Explorer',
                'earnedPoints' => [
                    [
                        'id' => 301,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => 1200,
                        'date' => '2026-01-01 00:00:00',
                        'description' => 'Balance transfer from old system',
                        'status' => 'active',
                    ],
                    [
                        'id' => 302,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => 300,
                        'date' => '2026-01-01 00:00:00',
                        'description' => 'Journey points',
                        'status' => 'active',
                    ],
                ],
            ],
        ]);

        $balanceTransferPoint = EarnedPoint::where('old_id', 301)->firstOrFail();
        $normalPoint = EarnedPoint::where('old_id', 302)->firstOrFail();

        $this->assertEquals('2024-12-31', $balanceTransferPoint->expires_at->toDateString());
        $this->assertEquals('2028-01-01', $normalPoint->expires_at->toDateString());
    }

    public function test_import_skips_legacy_negative_expiration_rows()
    {
        $gratitudeNumber = 'G-IMPORT-EXPIRY';

        $this->gratitudeService->import([
            [
                'id' => 987,
                'gratitudeNumber' => $gratitudeNumber,
                'level' => 'Explorer',
                'earnedPoints' => [
                    [
                        'id' => 401,
                        'gratitudeNumber' => $gratitudeNumber,
                        'journey_id' => 9001,
                        'points' => 1699,
                        'date' => Carbon::today()->subYears(3)->toDateTimeString(),
                        'description' => 'Journey points',
                        'status' => 'active',
                    ],
                    [
                        'id' => 402,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => -1699,
                        'date' => Carbon::today()->subYears(3)->toDateTimeString(),
                        'description' => 'Points Expired (2+ years)',
                        'status' => 'active',
                    ],
                ],
            ],
        ]);

        $this->assertDatabaseMissing('earned_points', [
            'old_id' => 402,
            'points' => -1699,
        ]);

        $this->assertDatabaseMissing('cancellations', [
            'gratitudeNumber' => $gratitudeNumber,
            'points' => 1699,
            'description' => 'Points Expired (2+ years)',
        ]);

        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->first();
        $this->assertEquals(1699, $gratitude->totalExpiredPoints);
        $this->assertEquals(0, $gratitude->totalRemainingPoints);
    }

    public function test_import_preserves_summary_balances_without_point_datasets()
    {
        $gratitudeNumber = 'G-IMPORT-SUMMARY';

        $this->gratitudeService->import([
            [
                'id' => 992,
                'gratitudeNumber' => $gratitudeNumber,
                'totalPoints' => 5000,
                'useablePoints' => 4200,
                'level' => 'Globetrotter',
                'status' => '1',
                'statusChange' => '1',
                'importStatus' => 1,
            ],
        ]);

        $this->assertDatabaseHas('gratitudes', [
            'old_id' => 992,
            'gratitudeNumber' => $gratitudeNumber,
            'totalPoints' => 5000,
            'useablePoints' => 4200,
            'level' => 'Explorer',
        ]);
    }

    public function test_import_defaults_null_account_status_to_active()
    {
        $gratitudeNumber = 'G-IMPORT-NULL-STATUS';

        $this->gratitudeService->import([
            [
                'id' => 991,
                'gratitudeNumber' => $gratitudeNumber,
                'status' => null,
            ],
        ]);

        $this->assertDatabaseHas('gratitudes', [
            'old_id' => 991,
            'gratitudeNumber' => $gratitudeNumber,
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    public function test_internal_import_fetches_detail_payloads_for_summary_gratitudes()
    {
        config([
            'services.aivteam.base_url' => 'https://aivteam.test',
            'services.aivteam.access_token' => 'test-token',
        ]);

        $summary = [
            'id' => 66,
            'old_id' => 66,
            'gratitudeNumber' => 'G0005',
            'totalPoints' => 287042,
            'useablePoints' => 152192,
            'level' => 'Jetsetter',
            'status' => '1',
            'statusChange' => '1',
            'importStatus' => 1,
            'created_at' => '2024-02-29T07:24:51.000000Z',
            'updated_at' => '2026-04-28T07:51:18.000000Z',
            'expires_at' => '2027-08-11T22:00:00.000000Z',
        ];

        Http::fake([
            'https://aivteam.test/api/gratitude/get/gratitude-data-all-by-status/gratitude/active' => Http::response([$summary]),
            'https://aivteam.test/api/get/all/journeys' => Http::response([
                ['id' => 501, 'endDate' => '2026-01-10'],
            ]),
            'https://aivteam.test/api/gratitude/get/gratitude-data-all/gratitude/G0005' => Http::response([
                'status' => true,
                'data' => [
                    'gratitude' => $summary,
                    'cancellationPoints' => [],
                    'earnedPoints' => [
                        [
                            'id' => 412,
                            'old_id' => 457,
                            'user_id' => null,
                            'journey_id' => '501',
                            'gratitudeNumber' => 'G0005',
                            'points' => '1234',
                            'redeemed_points' => 0,
                            'amount' => '1234',
                            'date' => '2026-01-01T00:00:00.000000Z',
                            'description' => 'Tier Points Earned on Journey',
                            'category' => null,
                            'cancel_id' => null,
                            'status' => '1',
                            'created_at' => '2026-01-01T00:00:00.000000Z',
                            'updated_at' => '2026-01-01T00:00:00.000000Z',
                        ],
                    ],
                    'bonusPoints' => [
                        [
                            'id' => 5,
                            'old_id' => 5,
                            'journey_id' => null,
                            'date' => '2026-01-02T00:00:00.000000Z',
                            'user_id' => null,
                            'category' => null,
                            'type' => null,
                            'gratitudeNumber' => 'G0005',
                            'points' => '200',
                            'redeemed_points' => 0,
                            'amount' => null,
                            'description' => 'Referral bonus',
                            'cancel_id' => null,
                            'status' => '1',
                            'created_at' => '2026-01-02T00:00:00.000000Z',
                            'updated_at' => '2026-01-02T00:00:00.000000Z',
                        ],
                    ],
                    'redeemPoints' => [],
                ],
            ]),
            'https://aivteam.test/api/gratitude/get/gratitude-by-number/G0005' => Http::response([
                'data' => [
                    'guests' => [
                        [
                            'id' => 91,
                            'first_name' => 'Ava',
                            'last_name' => 'Primary',
                            'preferred_name' => 'A',
                            'date_of_birth' => '1991-11-09',
                            'email' => 'ava@example.com',
                            'gratitude_ownership' => 'primary',
                        ],
                    ],
                ],
            ]),
        ]);

        $summaryResponse = $this->actingAs($this->user)->getJson('/internal-api/gratitude/migrate-data/active');

        $summaryResponse
            ->assertOk()
            ->assertJsonPath('import_status', 'active')
            ->assertJsonPath('summary_accounts', 1)
            ->assertJsonPath('imported_accounts', 1);

        $response = $this->actingAs($this->user)->getJson('/internal-api/gratitude/migrate-account-data/active');

        $response
            ->assertOk()
            ->assertJsonPath('import_status', 'active')
            ->assertJsonPath('detailed_accounts', 1)
            ->assertJsonPath('detail_failures', 0);

        Http::assertSent(fn ($request) => $request->url() === 'https://aivteam.test/api/gratitude/get/gratitude-data-all-by-status/gratitude/active');
        Http::assertSent(fn ($request) => $request->url() === 'https://aivteam.test/api/gratitude/get/gratitude-data-all/gratitude/G0005');
        Http::assertSent(fn ($request) => $request->url() === 'https://aivteam.test/api/gratitude/get/gratitude-by-number/G0005');

        $this->assertDatabaseHas('earned_points', [
            'old_id' => 412,
            'gratitudeNumber' => 'G0005',
            'points' => 1234,
        ]);

        $this->assertDatabaseHas('bonus_points', [
            'old_id' => 5,
            'gratitudeNumber' => 'G0005',
            'points' => 200,
        ]);

        $gratitude = Gratitude::where('gratitudeNumber', 'G0005')->firstOrFail();
        $this->assertSame([
            [
                'id' => 91,
                'first_name' => 'Ava',
                'last_name' => 'Primary',
                'preferred_name' => 'A',
                'email' => 'ava@example.com',
                'birthday' => '1991-11-09',
                'ownership' => 'primary',
            ],
        ], $gratitude->guests_data);
    }

    public function test_account_detail_import_fetches_current_account_payload()
    {
        config([
            'services.aivteam.base_url' => 'https://aivteam.test',
            'services.aivteam.access_token' => 'test-token',
        ]);

        Gratitude::create([
            'old_id' => 88,
            'gratitudeNumber' => 'G0007',
            'totalPoints' => 0,
            'useablePoints' => 0,
            'level' => 'Explorer',
            'status' => 'active',
            'is_active' => true,
            'importStatus' => true,
            'level_obtained_at' => Carbon::today(),
        ]);

        $summary = [
            'id' => 88,
            'old_id' => 88,
            'gratitudeNumber' => 'G0007',
            'totalPoints' => 900,
            'useablePoints' => 450,
            'level' => 'Explorer',
            'status' => 'active',
            'importStatus' => 1,
        ];

        Http::fake([
            'https://aivteam.test/api/get/all/journeys' => Http::response([
                ['id' => 901, 'endDate' => '2026-03-15'],
            ]),
            'https://aivteam.test/api/gratitude/get/gratitude-data-all/gratitude/G0007' => Http::response([
                'status' => true,
                'data' => [
                    'gratitude' => $summary,
                    'cancellationPoints' => [],
                    'earnedPoints' => [
                        [
                            'id' => 712,
                            'user_id' => null,
                            'journey_id' => '901',
                            'gratitudeNumber' => 'G0007',
                            'points' => '900',
                            'redeemed_points' => 0,
                            'amount' => '900',
                            'date' => '2026-03-01T00:00:00.000000Z',
                            'description' => 'Tier Points Earned on Journey',
                            'category' => null,
                            'cancel_id' => null,
                            'status' => '1',
                            'created_at' => '2026-03-01T00:00:00.000000Z',
                            'updated_at' => '2026-03-01T00:00:00.000000Z',
                        ],
                    ],
                    'bonusPoints' => [],
                    'redeemPoints' => [],
                ],
            ]),
            'https://aivteam.test/api/gratitude/get/gratitude-by-number/G0007' => Http::response([
                'data' => [
                    'guests' => [
                        [
                            'id' => 301,
                            'first_name' => 'Noah',
                            'last_name' => 'Import',
                            'email' => 'noah@example.com',
                            'gratitude_ownership' => 'primary',
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($this->user)->postJson('/internal-api/gratitude/account/G0007/import');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Account imported successfully')
            ->assertJsonPath('gratitude_number', 'G0007')
            ->assertJsonPath('detail_failures', 0);

        Http::assertSent(fn ($request) => $request->url() === 'https://aivteam.test/api/gratitude/get/gratitude-data-all/gratitude/G0007');
        Http::assertSent(fn ($request) => $request->url() === 'https://aivteam.test/api/gratitude/get/gratitude-by-number/G0007');

        $this->assertDatabaseHas('earned_points', [
            'old_id' => 712,
            'gratitudeNumber' => 'G0007',
            'points' => 900,
        ]);

        $gratitude = Gratitude::where('gratitudeNumber', 'G0007')->firstOrFail();
        $this->assertSame([
            [
                'id' => 301,
                'first_name' => 'Noah',
                'last_name' => 'Import',
                'preferred_name' => null,
                'email' => 'noah@example.com',
                'birthday' => null,
                'ownership' => 'primary',
            ],
        ], $gratitude->guests_data);
    }

    public function test_internal_import_fetches_inactive_summary_endpoint()
    {
        config([
            'services.aivteam.base_url' => 'https://aivteam.test',
            'services.aivteam.access_token' => 'test-token',
        ]);

        $summary = [
            'id' => 77,
            'old_id' => 77,
            'gratitudeNumber' => 'G0006',
            'totalPoints' => 500,
            'useablePoints' => 200,
            'level' => 'Explorer',
            'status' => 'inactive',
            'importStatus' => 1,
        ];

        Http::fake([
            'https://aivteam.test/api/gratitude/get/gratitude-data-all-by-status/gratitude/inactive' => Http::response([$summary]),
            'https://aivteam.test/api/get/all/journeys' => Http::response([]),
            'https://aivteam.test/api/gratitude/get/gratitude-data-all/gratitude/G0006' => Http::response([
                'status' => true,
                'data' => [
                    'gratitude' => $summary,
                    'cancellationPoints' => [],
                    'earnedPoints' => [],
                    'bonusPoints' => [],
                    'redeemPoints' => [],
                ],
            ]),
            'https://aivteam.test/api/gratitude/get/gratitude-by-number/G0006' => Http::response(['data' => ['guests' => []]]),
        ]);

        $summaryResponse = $this->actingAs($this->user)->getJson('/internal-api/gratitude/migrate-data/inactive');

        $summaryResponse
            ->assertOk()
            ->assertJsonPath('import_status', 'inactive')
            ->assertJsonPath('summary_accounts', 1)
            ->assertJsonPath('imported_accounts', 1);

        $response = $this->actingAs($this->user)->getJson('/internal-api/gratitude/migrate-account-data/inactive');

        $response
            ->assertOk()
            ->assertJsonPath('import_status', 'inactive')
            ->assertJsonPath('summary_accounts', 1)
            ->assertJsonPath('detailed_accounts', 1);

        Http::assertSent(fn ($request) => $request->url() === 'https://aivteam.test/api/gratitude/get/gratitude-data-all-by-status/gratitude/inactive');
        Http::assertSent(fn ($request) => $request->url() === 'https://aivteam.test/api/gratitude/get/gratitude-data-all/gratitude/G0006');

        $this->assertDatabaseHas('gratitudes', [
            'old_id' => 77,
            'gratitudeNumber' => 'G0006',
            'status' => 'inactive',
            'is_active' => false,
        ]);
    }

    public function test_import_turns_legacy_negative_non_expiry_rows_into_cancellations()
    {
        $gratitudeNumber = 'G-IMPORT-CANCEL';

        $this->gratitudeService->import([
            [
                'id' => 988,
                'gratitudeNumber' => $gratitudeNumber,
                'level' => 'Explorer',
                'earnedPoints' => [
                    [
                        'id' => 501,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => -250,
                        'date' => Carbon::today()->toDateTimeString(),
                        'description' => 'Manual point correction',
                        'category' => 'guest',
                        'status' => 'active',
                    ],
                ],
            ],
        ]);

        $this->assertDatabaseMissing('earned_points', [
            'old_id' => 501,
            'points' => -250,
        ]);

        $this->assertDatabaseHas('cancellations', [
            'old_id' => -1000000501,
            'gratitudeNumber' => $gratitudeNumber,
            'points' => 250,
            'description' => 'Manual point correction',
        ]);
    }

    public function test_import_skips_and_deletes_legacy_expiry_cancellations()
    {
        $gratitudeNumber = 'G-IMPORT-CANCEL-EXPIRY';

        Cancellation::create([
            'old_id' => 701,
            'gratitudeNumber' => $gratitudeNumber,
            'points' => 500,
            'description' => 'Expired points',
        ]);

        $reasonMatchedCancellation = Cancellation::create([
            'old_id' => 704,
            'gratitudeNumber' => $gratitudeNumber,
            'points' => 150,
            'description' => 'Legacy birthday point removal',
        ]);

        $earnedPoint = EarnedPoint::create([
            'old_id' => 705,
            'gratitudeNumber' => $gratitudeNumber,
            'cancel_id' => $reasonMatchedCancellation->id,
            'points' => 1000,
            'cancelled_points' => 150,
            'date' => Carbon::today(),
            'usable_date' => Carbon::today(),
            'status' => true,
        ]);

        $bonusPoint = BonusPoint::create([
            'old_id' => 706,
            'gratitudeNumber' => $gratitudeNumber,
            'cancel_id' => $reasonMatchedCancellation->id,
            'points' => 500,
            'cancelled_points' => 150,
            'date' => Carbon::today(),
            'usable_date' => Carbon::today(),
            'status' => true,
        ]);

        $this->gratitudeService->import([
            [
                'id' => 989,
                'gratitudeNumber' => $gratitudeNumber,
                'level' => 'Explorer',
                'cancellationPoints' => [
                    [
                        'id' => 701,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => 500,
                        'description' => 'Expired points',
                        'date' => Carbon::today()->toDateTimeString(),
                    ],
                    [
                        'id' => 702,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => 200,
                        'description' => 'program retired',
                        'date' => Carbon::today()->toDateTimeString(),
                    ],
                    [
                        'id' => 703,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => 100,
                        'description' => 'points expired (+2 years)',
                        'date' => Carbon::today()->toDateTimeString(),
                    ],
                    [
                        'id' => 704,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => 150,
                        'reason' => 'No longer awarding birthday points',
                        'date' => Carbon::today()->toDateTimeString(),
                    ],
                    [
                        'id' => 707,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => 100,
                        'description' => 'Points will expire after 2 years',
                        'date' => Carbon::today()->toDateTimeString(),
                    ],
                ],
            ],
        ]);

        $this->assertDatabaseMissing('cancellations', [
            'old_id' => 701,
            'gratitudeNumber' => $gratitudeNumber,
        ]);
        $this->assertDatabaseMissing('cancellations', [
            'old_id' => 702,
            'gratitudeNumber' => $gratitudeNumber,
        ]);
        $this->assertDatabaseMissing('cancellations', [
            'old_id' => 703,
            'gratitudeNumber' => $gratitudeNumber,
        ]);
        $this->assertDatabaseMissing('cancellations', [
            'old_id' => 704,
            'gratitudeNumber' => $gratitudeNumber,
        ]);
        $this->assertDatabaseMissing('cancellations', [
            'old_id' => 707,
            'gratitudeNumber' => $gratitudeNumber,
        ]);

        $earnedPoint->refresh();
        $bonusPoint->refresh();

        $this->assertNull($earnedPoint->cancel_id);
        $this->assertEquals(0, $earnedPoint->cancelled_points);
        $this->assertNull($bonusPoint->cancel_id);
        $this->assertEquals(0, $bonusPoint->cancelled_points);
    }

    public function test_import_records_source_dates_for_valid_cancellations()
    {
        $gratitudeNumber = 'G-IMPORT-CANCEL-DATES';

        $this->gratitudeService->import([
            [
                'id' => 990,
                'gratitudeNumber' => $gratitudeNumber,
                'level' => 'Explorer',
                'cancellationPoints' => [
                    [
                        'id' => 801,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => 400,
                        'description' => 'Guest correction',
                        'date' => '2026-02-01 00:00:00',
                    ],
                ],
                'earnedPoints' => [
                    [
                        'id' => 802,
                        'gratitudeNumber' => $gratitudeNumber,
                        'journey_id' => 8801,
                        'cancel_id' => 801,
                        'points' => 1000,
                        'redeemed_points' => 100,
                        'cancelled_points' => 400,
                        'date' => '2026-01-01 00:00:00',
                        'description' => 'Journey points',
                        'status' => 'active',
                    ],
                ],
            ],
        ], [
            8801 => [
                'id' => 8801,
                'endDate' => '2026-01-10',
            ],
        ]);

        $cancellation = Cancellation::where('old_id', 801)->firstOrFail();
        $breakdown = $cancellation->points_breakdown;

        $this->assertEquals(EarnedPoint::class, $breakdown[0]['source_type']);
        $this->assertEquals(400, $breakdown[0]['points']);
        $this->assertEquals('2026-01-10', $breakdown[0]['effective_date']);
        $this->assertEquals('2028-01-10', $breakdown[0]['expires_at']);
        $this->assertEquals('2026-02-01', $breakdown[0]['cancellation_date']);
    }

    public function test_import_rebuilds_level_history_from_effective_earned_points_only()
    {
        $gratitudeNumber = 'G-IMPORT-LEVEL-HISTORY';

        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00'));

        $records = [
            [
                'id' => 991,
                'gratitudeNumber' => $gratitudeNumber,
                'level' => 'Explorer',
                'bonusPoints' => [
                    [
                        'id' => 901,
                        'gratitudeNumber' => $gratitudeNumber,
                        'points' => 50000,
                        'date' => '2026-01-05 00:00:00',
                        'description' => 'Bonus should not update level',
                        'status' => true,
                    ],
                ],
                'earnedPoints' => [
                    [
                        'id' => 902,
                        'gratitudeNumber' => $gratitudeNumber,
                        'journey_id' => 9901,
                        'points' => 100,
                        'date' => '2026-01-01 00:00:00',
                        'description' => 'First effective journey',
                        'status' => 'active',
                    ],
                    [
                        'id' => 903,
                        'gratitudeNumber' => $gratitudeNumber,
                        'journey_id' => 9902,
                        'points' => 15000,
                        'date' => '2026-02-01 00:00:00',
                        'description' => 'Second effective journey',
                        'status' => 'active',
                    ],
                    [
                        'id' => 904,
                        'gratitudeNumber' => $gratitudeNumber,
                        'journey_id' => 9903,
                        'points' => 40000,
                        'date' => '2026-05-01 00:00:00',
                        'description' => 'Future journey',
                        'status' => 'active',
                    ],
                ],
            ],
        ];
        $journeysMap = [
            9901 => ['id' => 9901, 'endDate' => '2026-01-10'],
            9902 => ['id' => 9902, 'endDate' => '2026-02-10'],
            9903 => ['id' => 9903, 'endDate' => '2026-05-15'],
        ];

        try {
            $this->gratitudeService->import($records, $journeysMap);
            $this->gratitudeService->import($records, $journeysMap);
        } finally {
            Carbon::setTestNow();
        }

        $gratitude = Gratitude::where('gratitudeNumber', $gratitudeNumber)->firstOrFail();
        $history = $gratitude->levelHistory;

        $this->assertEquals('Globetrotter', $gratitude->level);
        $this->assertCount(2, $history);
        $this->assertEquals('initial', $history[0]['changeType']);
        $this->assertEquals('Explorer', $history[0]['toLevel']);
        $this->assertEquals('2026-01-10', $history[0]['date']);
        $this->assertEquals(100, $history[0]['earnedPoints']);
        $this->assertEquals('upgrade', $history[1]['changeType']);
        $this->assertEquals('Globetrotter', $history[1]['toLevel']);
        $this->assertEquals('2026-02-10', $history[1]['date']);
        $this->assertEquals(15100, $history[1]['earnedPoints']);

        $futurePoint = EarnedPoint::where('old_id', 904)->firstOrFail();
        $this->assertEquals('pending', $futurePoint->status);
    }
}
