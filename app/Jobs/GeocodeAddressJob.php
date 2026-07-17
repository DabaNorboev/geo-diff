<?php

namespace App\Jobs;

use App\Models\Address;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Jobs\AggregateSessionStatsJob;

class GeocodeAddressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // bbox Красноярска с небольшим запасом
    private const BBOX = [
        'lat_min' => 55.8,
        'lat_max' => 56.2,
        'lon_min' => 92.5,
        'lon_max' => 93.2,
    ];

    public function __construct(private Address $address)
    {
    }

    public function backoff(): array
    {
        return [
            5 + random_int(0, 2),
            15 + random_int(0, 5),
            30 + random_int(0, 10),
        ];
    }

    public function handle(): void
    {
        $nominatim = $this->geocode('nominatim', $this->address->normalized_address);
        $photon    = $this->geocode('photon',    $this->address->normalized_address);

        // отсекаем точки за пределами города
        if ($nominatim && !$this->isWithinCity($nominatim['lat'], $nominatim['lon'])) {
            Log::info("Nominatim вернул точку за пределами Красноярска для '{$this->address->normalized_address}': {$nominatim['lat']}, {$nominatim['lon']}");
            $nominatim = null;
        }

        if ($photon && !$this->isWithinCity($photon['lat'], $photon['lon'])) {
            Log::info("Photon вернул точку за пределами Красноярска для '{$this->address->normalized_address}': {$photon['lat']}, {$photon['lon']}");
            $photon = null;
        }

        if (!$nominatim && !$photon) {
            $this->address->update([
                'status'        => 'failed',
                'error_message' => 'Оба геокодера не смогли определить координаты в пределах города',
            ]);
            $this->finalizeSessionProgress();
            return;
        }

        $this->address->update([
            'nominatim_lat' => $nominatim['lat'] ?? null,
            'nominatim_lon' => $nominatim['lon'] ?? null,
            'nominatim_display_name' => $nominatim['display_name'] ?? null,
            'photon_lat'    => $photon['lat'] ?? null,
            'photon_lon'    => $photon['lon'] ?? null,
            'photon_display_name'    => $photon['display_name'] ?? null,
            'status'        => 'geocoded',
        ]);

        if ($nominatim) {
            DB::statement(
                'UPDATE addresses SET nominatim_geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                [$nominatim['lon'], $nominatim['lat'], $this->address->id]
            );
        }

        if ($photon) {
            DB::statement(
                'UPDATE addresses SET photon_geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
                [$photon['lon'], $photon['lat'], $this->address->id]
            );
        }

        if ($nominatim && $photon) {
            $distance = DB::selectOne(
                'SELECT ST_Distance(nominatim_geom::geography, photon_geom::geography) AS d FROM addresses WHERE id = ?',
                [$this->address->id]
            )->d;

            $threshold = $this->address->session->threshold_meters;

            $this->address->update([
                'distance_meters' => $distance,
                'is_problem'      => $distance > $threshold,
            ]);
        }

        $this->assignDistrict();
        $this->finalizeSessionProgress();
    }

    private function isWithinCity(float $lat, float $lon): bool
    {
        return $lat >= self::BBOX['lat_min']
            && $lat <= self::BBOX['lat_max']
            && $lon >= self::BBOX['lon_min']
            && $lon <= self::BBOX['lon_max'];
    }

    private function geocode(string $provider, string $address): ?array
    {
        $version  = config('geocoding.cache_version', 1);
        $cacheKey = "geocode:v{$version}:{$provider}:" . md5($address);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $result = $provider === 'nominatim'
            ? $this->queryNominatim($address)
            : $this->queryPhoton($address);

        // успех кэшируем надолго, легитимный отказ — на короткий срок
        // (см. config/geocoding.php: cache_ttl_found_days / cache_ttl_not_found_hours)
        $ttl = $result !== null
            ? now()->addDays(config('geocoding.cache_ttl_found_days', 30))
            : now()->addHours(config('geocoding.cache_ttl_not_found_hours', 6));

        Cache::put($cacheKey, $result, $ttl);

        return $result;
    }

    /**
     * @throws ConnectionException|RequestException при сетевой/HTTP-ошибке —
     *         намеренно не ловим здесь, чтобы сработал retry джобы.
     */
    private function queryNominatim(string $address): ?array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'GeoDiff-Diploma/1.0 (educational project)',
        ])
            ->timeout(15)
            ->get('https://nominatim.openstreetmap.org/search', [
                'q'      => $address,
                'format' => 'jsonv2',
                'limit'  => 1,
            ]);

        // бросит RequestException при 4xx/5xx — это НЕ "не найдено", это сбой сервиса
        $response->throw();

        $data = $response->json();

        if (empty($data)) {
            return null;
        }

        return [
            'lat'          => (float) $data[0]['lat'],
            'lon'          => (float) $data[0]['lon'],
            'display_name' => $this->shortenNominatimName($data[0]['display_name'] ?? null),
        ];
    }

    /**
     * @throws ConnectionException|RequestException при сетевой/HTTP-ошибке —
     *         намеренно не ловим здесь, чтобы сработал retry джобы.
     */
    private function queryPhoton(string $address): ?array
    {
        $response = Http::timeout(15)->get('https://photon.komoot.io/api/', [
            'q'     => $address,
            'limit' => 1,
        ]);

        $response->throw();

        $data    = $response->json();
        $feature = $data['features'][0] ?? null;

        if (!$feature) return null;

        $coords = $feature['geometry']['coordinates'] ?? null;
        if (!$coords) return null;

        $props = $feature['properties'] ?? [];

        // Photon вернул только город без улицы — считаем как не найдено
        if (empty($props['street']) && empty($props['name'])) {
            Log::info("Photon вернул только город для '{$address}', пропускаем");
            return null;
        }

        $parts = array_filter([
            $props['street']      ?? null,
            $props['housenumber'] ?? null,
            $props['city']        ?? null,
        ]);

        $displayName = !empty($parts)
            ? implode(', ', $parts)
            : ($props['name'] ?? null);

        return [
            'lon'          => (float) $coords[0],
            'lat'          => (float) $coords[1],
            'display_name' => $displayName,
        ];
    }

    private function assignDistrict(): void
    {
        DB::statement("
            UPDATE addresses a
            SET district_id = d.id
            FROM districts d
            WHERE a.id = ?
            AND ST_Within(COALESCE(a.nominatim_geom, a.photon_geom), d.geom)
        ", [$this->address->id]);
    }

    private function finalizeSessionProgress(): void
    {
        $session = $this->address->session;

        DB::update("
            UPDATE geocoding_sessions
            SET processed_addresses = processed_addresses + 1,
                status = CASE
                    WHEN processed_addresses + 1 >= total_addresses THEN 'completed'
                    ELSE status
                END
            WHERE id = ?
        ", [$session->id]);

        $fresh = $session->fresh();

        if ($fresh->status === 'completed' && $fresh->processed_addresses >= $fresh->total_addresses) {
            AggregateSessionStatsJob::dispatch($session->id)->afterCommit();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("GeocodeAddressJob окончательно провалилась для адреса #{$this->address->id} после всех попыток: {$exception->getMessage()}");

        $this->address->update([
            'status'        => 'failed',
            'error_message' => 'Сбой сети/сервиса геокодирования (после ' . $this->tries . ' попыток): ' . $exception->getMessage(),
        ]);

        $this->finalizeSessionProgress();
    }

    private function shortenNominatimName(?string $displayName): ?string
    {
        if (!$displayName) return null;

        // обрезаем по первому вхождению ", Красноярск"
        $cutoff = mb_strpos($displayName, ', Красноярск');
        if ($cutoff !== false) {
            return mb_substr($displayName, 0, $cutoff + mb_strlen(', Красноярск'));
        }

        return $displayName;
    }
}
