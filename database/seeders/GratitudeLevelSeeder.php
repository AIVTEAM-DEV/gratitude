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
                'earned_expire_days' => 730,
                'bonus_expire_days' => 730,
                'level_interval_years' => 2,
                'min_journeys' => 0,
                'jetsetter_min_journeys' => null,
                'jetsetter_min_journey_days' => null,
                'stay_active_rules' => 'Explorer is the default base level. No journey minimum is required to remain Explorer.',
                'level_rules' => [
                    [
                        'keep_level' => 'Explorer is retained by default during each 2-year cycle.',
                        'points_to_retain' => 0,
                        'journeys_to_retain' => 0,
                    ],
                    [
                        'downgrade_to' => null, // No downgrade from Explorer
                        'points_to_downgrade' => 0,
                    ],
                ],
                'terms_conditions' => 'Gratitude Rewards is subject to program terms. Points have no cash value outside the program. The program operator reserves the right to modify or terminate the program with reasonable notice. Points are non-transferable.',
                'level_terms_conditions' => 'Explorer members earn and redeem points on eligible experiences. Journey payment using points is not available at the Explorer level. To access journey payment benefits, accumulate enough earned points to reach Globetrotter status.',
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
                'earned_expire_days' => 730,
                'bonus_expire_days' => 730,
                'level_interval_years' => 2,
                'min_journeys' => 1,
                'jetsetter_min_journeys' => null,
                'jetsetter_min_journey_days' => null,
                'stay_active_rules' => 'Earn 15,001 to 30,000 eligible earned points and travel on at least 1 journey within the 2-year cycle to retain Globetrotter status.',
                'level_rules' => [
                    [
                        'keep_level' => 'Earn 15,001 to 30,000 eligible earned points and travel on at least 1 journey within the 2-year cycle to retain Globetrotter status.',
                        'points_to_earn' => 15001,
                        'journeys_to_earn' => 1,
                    ],
                    [
                        'downgrade_to' => 'Explorer',
                        'points_to_downgrade' => 15000,
                    ],
                ],
                'terms_conditions' => 'Gratitude Rewards is subject to program terms. Points have no cash value outside the program. The program operator reserves the right to modify or terminate the program with reasonable notice. Points are non-transferable.',
                'level_terms_conditions' => 'Globetrotter members may use points to pay for journeys and experiences. Complimentary upgrades are subject to availability at the time of check-in and are not guaranteed. Failure to accumulate the required points within the membership interval will result in downgrade to Explorer.',
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
                'earned_expire_days' => 730,
                'bonus_expire_days' => 730,
                'level_interval_years' => 2,
                'min_journeys' => 2,
                'jetsetter_min_journeys' => 2,
                'jetsetter_min_journey_days' => null,
                'stay_active_rules' => 'Earn more than 30,000 eligible earned points and travel on at least 2 journeys within the 2-year cycle to retain Jetsetter status.',
                'level_rules' => [
                    [
                        'keep_level' => 'Earn more than 30,000 eligible earned points and travel on at least 2 journeys within the 2-year cycle to retain Jetsetter status.',
                        'points_to_earn' => 30001,
                        'journeys_to_earn' => 2,
                    ],
                    [
                        'downgrade_to' => 'Globetrotter',
                        'journeys_to_downgrade' => 1,
                    ],
                ],
                'terms_conditions' => 'Gratitude Rewards is subject to program terms. Points have no cash value outside the program. The program operator reserves the right to modify or terminate the program with reasonable notice. Points are non-transferable.',
                'level_terms_conditions' => 'Jetsetter status requires both a points threshold (30,001+ earned points) and a travel activity threshold (minimum 2 journeys) within the membership interval. Failure to meet either condition at interval expiry will result in downgrade according to the cycle rules.',
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
