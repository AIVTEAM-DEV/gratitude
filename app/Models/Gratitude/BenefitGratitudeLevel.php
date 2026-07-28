<?php

namespace App\Models\Gratitude;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BenefitGratitudeLevel extends Pivot
{
    protected $table = 'benefit_gratitude_level';

    protected $fillable = [
        'gratitude_benefit_id',
        'gratitude_level_id',
        'description',
        'value',
        'value_type',
        'calculation',
        'is_active',
        'web_status',
    ];

    protected $casts = [
        'calculation' => 'array',
        'is_active' => 'boolean',
        'web_status' => 'boolean',
    ];
}
