<?php

namespace App\Models\Gratitude;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'guests_data',
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
        'guests_data' => 'array',
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

    public static function extractGuestsData(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return self::normalizeGuestsData($payload);
        }

        foreach (['guests_data', 'guests', 'members'] as $key) {
            if (array_key_exists($key, $payload)) {
                return self::normalizeGuestsData($payload[$key]);
            }
        }

        if (isset($payload['data'])) {
            $guests = self::extractGuestsData($payload['data']);

            if ($guests !== []) {
                return $guests;
            }
        }

        foreach (['primary_guest', 'primaryGuest', 'lead_guest', 'leadGuest', 'secondary_guests', 'secondaryGuests', 'additional_guests', 'additionalGuests', 'companions'] as $key) {
            if (array_key_exists($key, $payload)) {
                return self::normalizeGuestsData($payload);
            }
        }

        return [];
    }

    public static function normalizeGuestsData(mixed $payload): array
    {
        return collect(self::normalizeGuestPayload($payload))
            ->map(fn (array $guest) => self::normalizeGuestRecord($guest))
            ->filter(fn (array $guest) => collect($guest)->contains(fn ($value) => $value !== null && $value !== ''))
            ->values()
            ->all();
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

    private static function normalizeGuestRecord(array $guest): array
    {
        $nameParts = self::splitGuestName($guest);
        $ownership = self::firstGuestValue($guest, [
            'ownership',
            'onwership',
            'gratitude_ownership',
            'gratitudeOwnership',
            'role',
            'type',
            'guest_type',
            'guestType',
        ]);

        if ($ownership === null) {
            $isPrimary = self::firstGuestValue($guest, ['is_primary', 'isPrimary', 'primary', 'primary_guest', 'primaryGuest']);
            $ownership = self::guestFlag($isPrimary) ? 'primary' : null;
        }

        return [
            'id' => self::firstGuestValue($guest, ['id', 'guest_id', 'guestId', 'user_id', 'customer_id']),
            'first_name' => self::firstGuestValue($guest, ['first_name', 'firstName', 'firstname', 'given_name', 'givenName']) ?? ($nameParts[0] ?? null),
            'last_name' => self::firstGuestValue($guest, ['last_name', 'lastName', 'lastname', 'surname', 'family_name', 'familyName']) ?? ($nameParts[1] ?? null),
            'preferred_name' => self::firstGuestValue($guest, ['preferred_name', 'preferredName', 'nickname', 'known_as', 'knownAs']),
            'email' => self::firstGuestValue($guest, ['email', 'email_address', 'emailAddress', 'guest_email', 'guestEmail', 'contact_email', 'contactEmail', 'user.email', 'profile.email', 'contact.email']),
            'birthday' => self::firstGuestValue($guest, ['birthday', 'birtday', 'date_of_birth', 'dateOfBirth', 'birth_date', 'birthDate', 'dob']),
            'ownership' => $ownership,
        ];
    }

    private static function firstGuestValue(array $guest, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($guest, $key);

            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                return $value;
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function splitGuestName(array $guest): array
    {
        $name = self::firstGuestValue($guest, ['name', 'full_name', 'fullName', 'guest_name', 'guestName', 'display_name', 'displayName']);

        if ($name === null) {
            return [];
        }

        return preg_split('/\s+/', trim((string) $name), 2) ?: [];
    }

    private static function guestFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_scalar($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'primary'], true);
    }
}
