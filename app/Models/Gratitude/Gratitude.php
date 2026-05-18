<?php

namespace App\Models\Gratitude;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Gratitude extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    // Gratitudes are identified by gratitudeNumber — there is no user_id on this table.
    protected $fillable = [
        'old_id',
        'gratitudeNumber',
        'totalPoints',
        'totalEarnedPoints',
        'totalBonusPoints',
        'totalExpiredPoints',
        'totalCancelledPoints',
        'totalRedeemedPoints',
        'totalRemainingPoints',
        'useablePoints',
        'nonUseablePoints',
        'level',
        'levelHistory',
        'level_obtained_at',
        'status',
        'statusChange',
        'statusChangeReason',
        'systemLevelUpdate',
        'is_active',
        'importStatus',
        'expires_at',
        'last_activity_at',
    ];

    protected $casts = [
        'importStatus' => 'boolean',
        'systemLevelUpdate' => 'boolean',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'level_obtained_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'levelHistory' => 'array',
    ];

    public function earnedPoints()
    {
        return $this->hasMany(EarnedPoint::class, 'gratitudeNumber', 'gratitudeNumber');
    }

    public function bonusPoints()
    {
        return $this->hasMany(BonusPoint::class, 'gratitudeNumber', 'gratitudeNumber');
    }

    public function cancellations()
    {
        return $this->hasMany(Cancellation::class, 'gratitudeNumber', 'gratitudeNumber');
    }

    public function redemptions()
    {
        return $this->hasMany(RedeemPoints::class, 'gratitudeNumber', 'gratitudeNumber');
    }

    public function levelConfig()
    {
        return $this->belongsTo(GratitudeLevel::class, 'level', 'name');
    }

    public function guests(int|float $timeout = 8, int|float $connectTimeout = 3): array
    {
        if (! $this->gratitudeNumber || ! config('services.aivteam.base_url')) {
            return [];
        }

        try {
            $response = Http::withoutVerifying()
                ->withToken(config('services.aivteam.access_token'))
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->get(rtrim((string) config('services.aivteam.base_url'), '/').'/api/gratitude/get/gratitude-by-number/'.rawurlencode($this->gratitudeNumber));
        } catch (\Throwable) {
            return [];
        }

        return $response->successful()
            ? self::normalizeGuestPayload($response->json())
            : [];
    }

    public static function normalizeGuestPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return self::asList($payload);
        }

        foreach (['guests', 'members'] as $key) {
            if (isset($payload[$key])) {
                return self::asList($payload[$key]);
            }
        }

        if (isset($payload['data'])) {
            return self::normalizeGuestPayload($payload['data']);
        }

        $groupedGuests = [];

        foreach (['primary_guest', 'primaryGuest', 'lead_guest', 'leadGuest'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach (self::asList($payload[$key]) as $guest) {
                    $guest['is_primary'] = true;
                    $groupedGuests[] = $guest;
                }
            }
        }

        foreach (['secondary_guests', 'secondaryGuests', 'additional_guests', 'additionalGuests', 'companions'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach (self::asList($payload[$key]) as $guest) {
                    $guest['is_primary'] = false;
                    $groupedGuests[] = $guest;
                }
            }
        }

        return $groupedGuests ?: self::asList($payload);
    }

    private static function asList(mixed $value): array
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
}
