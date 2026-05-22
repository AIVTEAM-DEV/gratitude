<?php

namespace App\Http\Controllers\InternalApi\Gratitude;

use App\Http\Controllers\Controller;
use App\Models\Gratitude\GratitudeBenefit;
use App\Models\Gratitude\GratitudeLevel;
use App\Services\Gratitude\GratitudeBenefitsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GratitudeBenefitController extends Controller
{
    public function __construct(protected GratitudeBenefitsService $benefitsService) {}

    public function importBenefits()
    {
        abort_unless(request()->user()?->hasRole('Developer'), 403, 'Only developers can run imports.');

        $this->prepareLongRunningImport();

        $getResponse = Http::timeout(120)->get('https://artinvoyage.com/wp-json/api/all-gratitude-benefits');

        if ($getResponse && $getResponse->successful()) {
            $data = $getResponse->json();

            if (isset($data['benefits'])) {
                // Pre-fetch levels. Handle "Jetesetter" typo with partial matching.
                $levels            = GratitudeLevel::get();
                $explorerLevel     = $levels->first(fn($l) => stripos($l->name, 'Explorer') !== false);
                $globetrotterLevel = $levels->first(fn($l) => stripos($l->name, 'Globetrotter') !== false);
                $jetsetterLevel    = $levels->first(fn($l) => stripos($l->name, 'Jetsetter') !== false || stripos($l->name, 'Jetesetter') !== false);

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
                            'is_active'   => 1,
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
        }

        return response()->json(['success' => false, 'message' => 'Failed to import benefits'], 500);
    }

    private function prepareLongRunningImport(): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        DB::disableQueryLog();
    }

    private function levelSyncData(mixed $value): array
    {
        $value = is_scalar($value) ? (string) $value : json_encode($value);

        return [
            'value'       => $value,
            'description' => $value,
            'is_active'   => 1,
            'web_status'  => 1,
        ];
    }
}
