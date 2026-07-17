<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeocodingSession extends Model
{
    use HasUuids;

    protected $guarded = false;

    protected $casts = [
        'avg_distance_meters' => 'float',
        'problem_rate' => 'float',
        'moran_i' => 'float',
    ];

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'session_id');
    }

    public function districtStats(): HasMany
    {
        return $this->hasMany(DistrictSessionStat::class, 'session_id');
    }
}
