<?php

namespace App\Models\Gratitude;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EarnedPoint extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    protected $fillable = [
        'old_id',
        'user_id',
        'journey_id',
        'cancel_id',
        'gratitudeNumber',
        'points',
        'points_breakdown',
        'redeemed_points',
        'cancelled_points',
        'redemption_history',
        'amount',
        'date',
        'description',
        'category',
        'status',
        'usable_date',
        'expires_at',
        'expires_at_manual',
        'project_data',
    ];

    protected $appends = [
        'remaining_points',
        'expired_points',
    ];

    protected $casts = [
        'usable_date' => 'date',
        'expires_at' => 'datetime',
        'expires_at_manual' => 'boolean',
        'status' => 'boolean',
        'date' => 'date',
        'redemption_history' => 'array',
        'points_breakdown' => 'array',
        'project_data' => 'array',
        'points' => 'integer',
        'redeemed_points' => 'integer',
        'cancelled_points' => 'integer',
    ];

    public function getRemainingPointsAttribute($value = null): int
    {
        return max(
            0,
            (int) $this->points - (int) $this->redeemed_points - (int) $this->cancelled_points
        );
    }

    public function getExpiredPointsAttribute(): int
    {
        if (! $this->expires_at || $this->expires_at->isFuture()) {
            return 0;
        }

        return $this->remaining_points;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function redemptions()
    {
        return $this->morphMany(RedeemPointsDetails::class, 'source');
    }

    public function cancellation()
    {
        return $this->belongsTo(Cancellation::class, 'cancel_id');
    }

    public function scopeActiveStatus(Builder $query): Builder
    {
        return $query->whereIn('status', [true, 1, '1']);
    }

    public function scopeUsableAsOf(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where(function ($q) use ($date) {
            $q->whereNull('usable_date')->orWhere('usable_date', '<=', $date);
        });
    }

    public function scopeNotExpiredAsOf(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where(function ($q) use ($date) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', $date);
        });
    }

    public function scopeWithRemainingPoints(Builder $query): Builder
    {
        return $query->whereRaw('COALESCE(points, 0) > COALESCE(redeemed_points, 0) + COALESCE(cancelled_points, 0)');
    }

    public function scopeRedeemable(Builder $query, string $gratitudeNumber, CarbonInterface $date): Builder
    {
        return $query
            ->where('gratitudeNumber', $gratitudeNumber)
            ->activeStatus()
            ->whereNull('cancel_id')
            ->notExpiredAsOf($date)
            ->usableAsOf($date)
            ->withRemainingPoints();
    }

    public function scopeQualifyingForLevel(Builder $query, string $gratitudeNumber, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->where('gratitudeNumber', $gratitudeNumber)
            ->activeStatus()
            ->whereNull('cancel_id')
            ->whereNotNull('usable_date')
            ->where('usable_date', '>=', $from)
            ->where('usable_date', '<=', $to);
    }

    public function setStatusAttribute(mixed $value): void
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));
        }

        $this->attributes['status'] = ! in_array($value, [false, 0, '0', 'false', 'inactive', 'expired', 'cancelled', 'canceled', 'rejected'], true);
    }
}
