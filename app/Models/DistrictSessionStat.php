<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistrictSessionStat extends Model
{
    protected $guarded = [];

    protected $casts = [
        'avg_distance_meters' => 'float',
        'problem_rate' => 'float',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(GeocodingSession::class, 'session_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
