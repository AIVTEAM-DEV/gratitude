<?php

namespace App\Models\Gratitude;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class GratitudeTermCondition extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'gratitude_terms_conditions';

    protected $fillable = [
        'title',
        'content',
        'status',
        'sort_order',
        'source_key',
    ];

    protected $casts = [
        'status' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }
}
