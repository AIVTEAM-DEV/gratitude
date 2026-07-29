<?php

namespace Database\Seeders;

use App\Models\Gratitude\GratitudeLevel;
use Illuminate\Database\Seeder;

class GratitudeLevelSeeder extends Seeder
{
    public function run(): void
    {
        // ── Levels ────────────────────────────────────────────────────────────
        $levels = [
            [
                'name' => 'Explorer',
                'min_points' => 0,
                'max_points' => 15000,
                'status' => true,
                'redemption_points_per_dollar' => 35,
                'partner_points_per_dollar' => 40,
                'gift_card_points_per_dollar' => 45,
                'airline_miles_points_per_dollar' => 45,
                'earned_expire_days' => 730,
                'bonus_expire_days' => 730,
                'level_interval_years' => 2,
                'min_journeys' => 0,
                'jetsetter_min_journeys' => null,
                'jetsetter_min_journey_days' => null,
                'stay_active_rules' => 'Explorer is the default base level. No journey minimum is required to remain Explorer.',
                'level_image' => null,
                'level_icon' => null,
            ],
            [
                'name' => 'Globetrotter',
                'min_points' => 15001,
                'max_points' => 30000,
                'status' => true,
                'redemption_points_per_dollar' => 30,
                'partner_points_per_dollar' => 35,
                'gift_card_points_per_dollar' => 40,
                'airline_miles_points_per_dollar' => 40,
                'earned_expire_days' => 730,
                'bonus_expire_days' => 730,
                'level_interval_years' => 2,
                'min_journeys' => 1,
                'jetsetter_min_journeys' => null,
                'jetsetter_min_journey_days' => null,
                'stay_active_rules' => 'Earn 15,001 to 30,000 eligible earned points and travel on at least 1 journey within the 2-year cycle to retain Globetrotter status.',
                'level_image' => null,
                'level_icon' => null,
            ],
            [
                'name' => 'Jetsetter',
                'min_points' => 30001,
                'max_points' => null,
                'status' => true,
                'redemption_points_per_dollar' => 25,
                'partner_points_per_dollar' => 30,
                'gift_card_points_per_dollar' => 35,
                'airline_miles_points_per_dollar' => 35,
                'earned_expire_days' => 730,
                'bonus_expire_days' => 730,
                'level_interval_years' => 2,
                'min_journeys' => 2,
                'jetsetter_min_journeys' => 2,
                'jetsetter_min_journey_days' => null,
                'stay_active_rules' => 'Earn more than 30,000 eligible earned points and travel on at least 2 journeys within the 2-year cycle to retain Jetsetter status.',
                'level_image' => null,
                'level_icon' => null,
            ],
        ];

        foreach ($levels as $levelData) {
            GratitudeLevel::updateOrCreate(
                ['name' => $levelData['name']],
                $levelData
            );
        }
    }
}
