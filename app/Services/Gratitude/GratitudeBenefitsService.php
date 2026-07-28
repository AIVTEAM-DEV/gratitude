<?php

namespace App\Services\Gratitude;

use App\Models\Gratitude\GratitudeBenefit;
use App\Models\Gratitude\GratitudeLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GratitudeBenefitsService
{
    public const RULE_TEXT = 'text';

    public const RULE_PER_PERSON = 'per_person';

    public const RULE_PER_ROOM = 'per_room';

    public const RULE_MILES = 'miles';

    public const RULE_POINTS_PER_DOLLAR = 'points_per_dollar';

    public const RULE_REFERRAL_POINTS = 'referral_points';

    public const RULE_KEYS = [
        self::RULE_TEXT,
        self::RULE_PER_PERSON,
        self::RULE_PER_ROOM,
        self::RULE_MILES,
        self::RULE_POINTS_PER_DOLLAR,
        self::RULE_REFERRAL_POINTS,
    ];

    public const MONETARY_RULE_KEYS = [
        self::RULE_PER_PERSON,
        self::RULE_PER_ROOM,
    ];

    /**
     * Get all gratitude levels.
     */
    public function getAllLevels()
    {
        return GratitudeLevel::orderBy('min_points')->get();
    }

    /**
     * Get all benefits.
     */
    public function getAllBenefits()
    {
        return GratitudeBenefit::with('levels')->get();
    }

    /**
     * Assign a benefit to a level with specific pivot values.
     */
    public function assignBenefitToLevel(int $benefitId, int $levelId, array $pivotData = [])
    {
        $benefit = GratitudeBenefit::findOrFail($benefitId);
        $benefit->levels()->syncWithoutDetaching([
            $levelId => $pivotData,
        ]);

        return true;
    }

    /**
     * Get formatted benefit grid.
     */
    public function getBenefitsGrid()
    {
        $levels = $this->getAllLevels();
        $benefits = $this->getAllBenefits();

        $grid = [];

        foreach ($benefits as $benefit) {
            $row = [
                'id' => $benefit->id,
                'name' => $benefit->name,
                'benefit_key' => $benefit->benefit_key,
                'description' => $benefit->description,
                'type' => $benefit->type,
                'is_active' => $benefit->is_active,
                'levels' => [],
            ];

            foreach ($levels as $level) {
                $levelPivot = $benefit->levels->firstWhere('id', $level->id);
                $rule = $levelPivot
                    ? $this->levelBenefitRule($benefit, $levelPivot->pivot)
                    : null;

                $row['levels'][$level->id] = [
                    'id' => $level->id,
                    'name' => $level->name,
                    'has_benefit' => $levelPivot !== null,
                    'value' => $levelPivot ? $levelPivot->pivot->value : null,
                    'rule_value' => $rule['value'] ?? null,
                    'formatted_value' => $rule['formatted_value'] ?? null,
                    'rule_key' => $rule['key'] ?? null,
                    'currency' => $rule['currency'] ?? null,
                    'description' => $levelPivot ? $levelPivot->pivot->description : null,
                    'value_type' => $levelPivot ? $levelPivot->pivot->value_type : null,
                    'calculation' => $rule['calculation'] ?? null,
                    'is_active' => $levelPivot ? $levelPivot->pivot->is_active : null,
                    'web_status' => $levelPivot ? $levelPivot->pivot->web_status : null,
                ];
            }

            $grid[] = $row;
        }

        return [
            'levels' => $levels,
            'grid' => $grid,
            'rule_options' => $this->ruleOptions(),
        ];
    }

    /**
     * Normalize and validate a level-benefit mapping before it is stored.
     */
    public function normalizeLevelMapping(
        GratitudeBenefit $benefit,
        array $mapping,
        string $field = 'level_mappings'
    ): array {
        $calculation = $this->calculationArray($mapping['calculation'] ?? null);
        $benefitDefaultRule = in_array($benefit->type, self::RULE_KEYS, true)
            ? $benefit->type
            : null;
        $key = $calculation['key']
            ?? $mapping['rule_key']
            ?? $benefitDefaultRule;

        if ($key !== null && ! in_array($key, self::RULE_KEYS, true)) {
            throw ValidationException::withMessages([
                "$field.calculation.key" => ['The selected benefit rule is invalid.'],
            ]);
        }

        if (! in_array($key, self::RULE_KEYS, true)) {
            $key = self::RULE_TEXT;
        }

        $value = $mapping['value'] ?? null;
        $currency = isset($calculation['currency'])
            ? strtoupper(trim((string) $calculation['currency']))
            : null;

        if ($key !== self::RULE_TEXT) {
            if ($value === null || $value === '' || ! is_numeric(str_replace(',', '', (string) $value))) {
                throw ValidationException::withMessages([
                    "$field.value" => ['The value must be a number for the selected benefit rule.'],
                ]);
            }

            $value = $this->normalizeNumericValue($value);
            if ((float) $value < 0) {
                throw ValidationException::withMessages([
                    "$field.value" => ['The value must be zero or greater.'],
                ]);
            }
        }

        if (in_array($key, self::MONETARY_RULE_KEYS, true)) {
            if (! $currency || ! preg_match('/^[A-Z]{3}$/', $currency)) {
                throw ValidationException::withMessages([
                    "$field.calculation.currency" => ['Choose a valid three-letter currency.'],
                ]);
            }
        } else {
            $currency = null;
        }

        $normalizedCalculation = ['key' => $key];
        if ($currency) {
            $normalizedCalculation['currency'] = $currency;
        }

        $isActive = filter_var($mapping['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $webStatus = $isActive
            ? filter_var($mapping['web_status'] ?? true, FILTER_VALIDATE_BOOLEAN)
            : false;

        return [
            'value' => $value !== null ? (string) $value : null,
            'description' => $mapping['description'] ?? null,
            'value_type' => $key === self::RULE_TEXT ? 'text' : 'number',
            'calculation' => json_encode($normalizedCalculation),
            'is_active' => $isActive,
            'web_status' => $webStatus,
        ];
    }

    /**
     * Return normalized rule metadata for structured and legacy level benefits.
     */
    public function levelBenefitRule(GratitudeBenefit $benefit, mixed $pivot): array
    {
        $rawValue = $pivot->value ?? null;
        $calculation = $this->calculationArray($pivot->calculation ?? null);
        $key = $calculation['key'] ?? null;
        $currency = isset($calculation['currency'])
            ? strtoupper((string) $calculation['currency'])
            : null;
        $value = $rawValue;

        if (! in_array($key, self::RULE_KEYS, true)) {
            [$key, $value, $currency] = $this->inferLegacyRule($benefit, $rawValue);
            $calculation = array_filter([
                'key' => $key,
                'currency' => $currency,
            ], fn ($item) => $item !== null);
        } elseif ($key !== self::RULE_TEXT && $rawValue !== null) {
            $value = $this->normalizeNumericValue($rawValue);
        }

        return [
            'key' => $key,
            'value' => $value,
            'currency' => $currency,
            'calculation' => $calculation,
            'formatted_value' => $this->formatRuleValue($key, $value, $currency),
        ];
    }

    public function ruleOptions(): array
    {
        return [
            ['key' => self::RULE_TEXT, 'label' => 'Text / Included'],
            ['key' => self::RULE_PER_PERSON, 'label' => 'Amount per person'],
            ['key' => self::RULE_PER_ROOM, 'label' => 'Amount per room'],
            ['key' => self::RULE_MILES, 'label' => 'Miles'],
            ['key' => self::RULE_POINTS_PER_DOLLAR, 'label' => 'Points per dollar'],
            ['key' => self::RULE_REFERRAL_POINTS, 'label' => 'Referral points'],
        ];
    }

    protected function calculationArray(mixed $calculation): array
    {
        if (is_array($calculation)) {
            return $calculation;
        }

        if (! is_string($calculation) || trim($calculation) === '') {
            return [];
        }

        $decoded = json_decode($calculation, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function inferLegacyRule(GratitudeBenefit $benefit, mixed $rawValue): array
    {
        $value = trim((string) ($rawValue ?? ''));
        $searchable = Str::lower($benefit->name.' '.$benefit->benefit_key.' '.$value);
        $numericValue = $this->firstNumber($value);
        $currency = $this->inferCurrency($value);

        if (str_contains($searchable, 'referral') && str_contains($searchable, 'point')) {
            return [self::RULE_REFERRAL_POINTS, $numericValue, null];
        }

        if (preg_match('/(?:\/|\bper\s+)person\b/i', $value)) {
            return [self::RULE_PER_PERSON, $numericValue, $currency ?? 'USD'];
        }

        if (preg_match('/(?:\/|\bper\s+)room\b/i', $value)) {
            return [self::RULE_PER_ROOM, $numericValue, $currency ?? 'USD'];
        }

        if (
            preg_match('/points?.*(?:\$|dollar)|(?:\$|dollar).*points?/i', $value)
            || str_contains($searchable, 'points_per_dollar')
        ) {
            return [self::RULE_POINTS_PER_DOLLAR, $this->pointsPerDollarFromText($value), null];
        }

        if (str_contains($searchable, 'mile')) {
            return [self::RULE_MILES, $numericValue, null];
        }

        return [self::RULE_TEXT, $rawValue, null];
    }

    protected function normalizeNumericValue(mixed $value): int|float|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '', (string) $value);
        if (! is_numeric($normalized)) {
            return $value;
        }

        $number = (float) $normalized;

        return floor($number) === $number ? (int) $number : $number;
    }

    protected function firstNumber(string $value): int|float|string|null
    {
        if (! preg_match('/\d[\d,]*(?:\.\d+)?/', $value, $matches)) {
            return null;
        }

        return $this->normalizeNumericValue($matches[0]);
    }

    protected function pointsPerDollarFromText(string $value): int|float|string|null
    {
        if (preg_match('/(\d[\d,]*(?:\.\d+)?)\s*points?/i', $value, $matches)) {
            return $this->normalizeNumericValue($matches[1]);
        }

        return $this->firstNumber($value);
    }

    protected function inferCurrency(string $value): ?string
    {
        if (preg_match('/\b([A-Z]{3})\s*[\d,]/', $value, $matches)) {
            return strtoupper($matches[1]);
        }

        return match (true) {
            str_contains($value, '$') => 'USD',
            str_contains($value, '€') => 'EUR',
            str_contains($value, '£') => 'GBP',
            str_contains($value, 'R') && preg_match('/\bR\s*[\d,]/', $value) => 'ZAR',
            default => null,
        };
    }

    protected function formatRuleValue(string $key, mixed $value, ?string $currency): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $formattedNumber = is_numeric($value)
            ? number_format((float) $value, floor((float) $value) === (float) $value ? 0 : 2)
            : (string) $value;

        return match ($key) {
            self::RULE_PER_PERSON => trim(($currency ?: 'USD').' '.$formattedNumber.' per person'),
            self::RULE_PER_ROOM => trim(($currency ?: 'USD').' '.$formattedNumber.' per room'),
            self::RULE_MILES => $formattedNumber.' miles',
            self::RULE_POINTS_PER_DOLLAR => $formattedNumber.' points per dollar',
            self::RULE_REFERRAL_POINTS => $formattedNumber.' referral points',
            default => (string) $value,
        };
    }

    /**
     * Check whether a given level (by name) has an active benefit identified by its benefit_key.
     *
     * Usage: levelHasBenefit('Explorer', 'journey_payment')  → false
     *        levelHasBenefit('Globetrotter', 'journey_payment') → true
     */
    public function levelHasBenefit(string $levelName, string $benefitKey): bool
    {
        $level = GratitudeLevel::where('name', $levelName)->first();
        if (! $level) {
            return false;
        }

        return $level->benefits()
            ->where('benefit_key', $benefitKey)
            ->where('gratitude_benefits.is_active', true)
            ->wherePivot('is_active', true)
            ->exists();
    }

    public function getGratitudeBenefits(): RedirectResponse
    {
        $getResponse = Http::post('https://artinvoyage.com/wp-json/api/all-gratitude-benefits');
        if ($getResponse && $getResponse->successful()) {
            $data = $getResponse->json();

            if (! empty($data['benefits'])) {
                $levels = GratitudeLevel::get();
                $explorerLevel = $levels->first(fn ($l) => stripos($l->name, 'Explorer') !== false);
                $globetrotterLevel = $levels->first(fn ($l) => stripos($l->name, 'Globetrotter') !== false);
                $jetsetterLevel = $levels->first(fn ($l) => stripos($l->name, 'Jetsetter') !== false || stripos($l->name, 'Jetesetter') !== false);

                foreach ($data['benefits'] as $value) {
                    $benefitData = $value['gratitude_benefit'];
                    $benefitName = $benefitData['benefit_name'];

                    // Find existing record by name (no benefit_key comes from the API)
                    $getBenefit = GratitudeBenefit::where('name', $benefitName)->first()
                                  ?? new GratitudeBenefit;

                    // Generate a benefit_key if the record doesn't already have one
                    $benefitKey = $getBenefit->benefit_key
                                  ?: $this->generateBenefitKey($benefitName);

                    $getBenefit->name = $benefitName;
                    $getBenefit->benefit_key = $benefitKey;
                    $getBenefit->description = $benefitData['benefit_description'] ?? null;
                    $getBenefit->is_active = 1;
                    $getBenefit->save();

                    // Sync level assignments
                    $syncData = [];

                    if ($explorerLevel && ! empty($value['gratitude_explorer'])) {
                        $syncData[$explorerLevel->id] = [
                            'value' => $value['gratitude_explorer'],
                            'description' => $value['gratitude_explorer'],
                            'is_active' => 1,
                            'web_status' => 1,
                        ];
                    }
                    if ($globetrotterLevel && ! empty($value['gratitude_globetrotter'])) {
                        $syncData[$globetrotterLevel->id] = [
                            'value' => $value['gratitude_globetrotter'],
                            'description' => $value['gratitude_globetrotter'],
                            'is_active' => 1,
                            'web_status' => 1,
                        ];
                    }
                    if ($jetsetterLevel && ! empty($value['gratitude_jetsetter'])) {
                        $syncData[$jetsetterLevel->id] = [
                            'value' => $value['gratitude_jetsetter'],
                            'description' => $value['gratitude_jetsetter'],
                            'is_active' => 1,
                            'web_status' => 1,
                        ];
                    }

                    $getBenefit->levels()->sync($syncData);
                }
            }
        }

        return Redirect::back();
    }

    /**
     * Use Claude (Anthropic API) to generate a concise snake_case benefit_key from
     * a human-readable benefit name.  Falls back to Str::snake() if the API is
     * unavailable or returns an unusable response.
     *
     * The generated key is also de-duplicated against existing keys in the DB.
     */
    public function generateBenefitKey(string $name): string
    {
        $apiKey = config('services.anthropic.api_key', env('ANTHROPIC_API_KEY'));
        $generated = null;

        if ($apiKey) {
            try {
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 20,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => "Generate a short snake_case identifier key for a travel loyalty program benefit named \"{$name}\". "
                                       .'Rules: lowercase only, words separated by underscores, no spaces or special characters, 1-4 words max. '
                                       .'Return only the key itself with no explanation, punctuation, or quotes. '
                                       .'Examples: journey_payment, room_upgrade, priority_support, service_discount.',
                        ],
                    ],
                ]);

                if ($response->successful()) {
                    $raw = trim($response->json('content.0.text') ?? '');
                    // Accept only valid snake_case strings
                    if ($raw && preg_match('/^[a-z][a-z0-9_]{1,49}$/', $raw)) {
                        $generated = $raw;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('generateBenefitKey: Claude API call failed', ['error' => $e->getMessage()]);
            }
        }

        // Fallback: derive key from the name using Str::snake
        if (! $generated) {
            $generated = Str::snake(Str::ascii($name));
            // Remove any characters that are not lowercase letters, digits, or underscores
            $generated = preg_replace('/[^a-z0-9_]/', '_', $generated);
            $generated = preg_replace('/_+/', '_', $generated);
            $generated = trim($generated, '_');
        }

        // Ensure uniqueness within the gratitude_benefits table
        $base = $generated;
        $index = 2;
        while (GratitudeBenefit::where('benefit_key', $generated)->exists()) {
            $generated = $base.'_'.$index++;
        }

        return $generated;
    }
}
