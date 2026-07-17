<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $guarded = [];

    protected $casts = [
        'nominatim_lat' => 'float',
        'nominatim_lon' => 'float',
        'photon_lat' => 'float',
        'photon_lon' => 'float',
        'distance_meters' => 'float',
        'is_problem' => 'boolean',
    ];

    protected $hidden = ['nominatim_geom', 'photon_geom'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(GeocodingSession::class, 'session_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
