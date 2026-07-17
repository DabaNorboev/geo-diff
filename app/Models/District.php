<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    protected $guarded = [];

    // geom — сырая PostGIS geometry (WKB), Eloquent её не парсит.
    // Достаём отдельным raw-запросом через ST_AsGeoJSON, когда нужно отдать во фронт.
    protected $hidden = ['geom'];

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(DistrictSessionStat::class);
    }
}
