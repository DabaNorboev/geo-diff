<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSessionRequest;
use App\Jobs\GeocodeAddressJob;
use App\Models\Address;
use App\Models\GeocodingSession;
use App\Services\AddressFileParser;
use App\Services\AddressNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SessionController extends Controller
{
    private const MAX_ADDRESSES = 300;

    public function __construct(
        private AddressNormalizer $normalizer,
        private AddressFileParser $fileParser,
    ) {
    }

    public function store(StoreSessionRequest $request)
    {
        $file = $request->file('file');

        try {
            $addresses = $this->fileParser->parse($file);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (empty($addresses)) {
            return response()->json([
                'message' => 'В файле не найдено ни одного адреса',
            ], 422);
        }

        if (count($addresses) > self::MAX_ADDRESSES) {
            return response()->json([
                'message' => 'Превышен лимит: максимум ' . self::MAX_ADDRESSES . ' адресов за сессию',
            ], 422);
        }

        $session = DB::transaction(function () use ($file, $addresses) {
            $session = GeocodingSession::create([
                'original_filename' => $file->getClientOriginalName(),
                'total_addresses' => count($addresses),
                'status' => 'processing',
            ]);

            // Задержку между диспатчами (rate limit Nominatim: 1 req/sec) ставим
            // только адресам, которых реально нет в кэше — иначе если сессия
            // почти полностью прогрета (см. geocode:cache-warm), она бы всё равно
            // тянулась секунда за адресом просто из-за порядкового номера в CSV,
            // хотя реального похода во внешний API для неё не требуется.
            $nextDelaySeconds = 0;

            foreach ($addresses as $rawAddress) {
                $normalized = $this->normalizer->normalize($rawAddress);

                $address = Address::create([
                    'session_id' => $session->id,
                    'raw_address' => $rawAddress,
                    'normalized_address' => $normalized,
                    'status' => 'pending',
                ]);

                if ($this->isFullyCached($normalized)) {
                    GeocodeAddressJob::dispatch($address)->afterCommit();
                } else {
                    GeocodeAddressJob::dispatch($address)
                        ->delay(now()->addSeconds($nextDelaySeconds))
                        ->afterCommit();
                    $nextDelaySeconds++;
                }
            }

            return $session;
        });

        return response()->json([
            'session_id' => $session->id,
            'total_addresses' => $session->total_addresses,
        ], 201);
    }

    /**
     * Проверяет, есть ли уже закэшированный результат ОБОИХ геокодеров
     * для данного нормализованного адреса. Если да — джобу можно
     * диспатчить без задержки, она не пойдёт во внешний API вообще.
     *
     * Ключи должны совпадать по формату с теми, что использует
     * GeocodeAddressJob::geocode() — geocode:v{version}:{provider}:md5(address)
     */
    private function isFullyCached(string $normalizedAddress): bool
    {
        $version = config('geocoding.cache_version', 1);
        $hash = md5($normalizedAddress);

        return Cache::has("geocode:v{$version}:nominatim:{$hash}")
            && Cache::has("geocode:v{$version}:photon:{$hash}");
    }

    public function show(GeocodingSession $session)
    {
        return response()->json([
            'session_id' => $session->id,
            'status' => $session->status,
            'total_addresses' => $session->total_addresses,
            'processed_addresses' => $session->processed_addresses,
            'avg_distance_meters' => $session->avg_distance_meters,
            'problem_rate' => $session->problem_rate,
            'moran_i' => $session->moran_i,
        ]);
    }

    public function exportPoints(GeocodingSession $session)
    {
        $rows = DB::select("
        SELECT
            a.raw_address,
            a.normalized_address,
            a.nominatim_lat,
            a.nominatim_lon,
            a.photon_lat,
            a.photon_lon,
            a.distance_meters,
            a.is_problem,
            d.name AS district_name,
            ST_AsGeoJSON(COALESCE(a.nominatim_geom, a.photon_geom)) AS geojson
        FROM addresses a
        LEFT JOIN districts d ON d.id = a.district_id
        WHERE a.session_id = ?
          AND (a.nominatim_geom IS NOT NULL OR a.photon_geom IS NOT NULL)
    ", [$session->id]);

        $features = array_map(function ($row) {
            return [
                'type' => 'Feature',
                'geometry' => json_decode($row->geojson),
                'properties' => [
                    'raw_address' => $row->raw_address,
                    'normalized_address' => $row->normalized_address,
                    'nominatim_lat' => $row->nominatim_lat !== null ? (float) $row->nominatim_lat : null,
                    'nominatim_lon' => $row->nominatim_lon !== null ? (float) $row->nominatim_lon : null,
                    'photon_lat' => $row->photon_lat !== null ? (float) $row->photon_lat : null,
                    'photon_lon' => $row->photon_lon !== null ? (float) $row->photon_lon : null,
                    'distance_meters' => $row->distance_meters !== null ? (float) $row->distance_meters : null,
                    'is_problem' => (bool) $row->is_problem,
                    'district_name' => $row->district_name,
                ],
            ];
        }, $rows);

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ])
            ->header('Content-Type', 'application/geo+json')
            ->header('Content-Disposition', 'attachment; filename="addresses.geojson"');
    }

    public function exportDistricts(GeocodingSession $session)
    {
        $rows = DB::select("
        SELECT
            d.name,
            d.osm_id,
            s.address_count,
            s.avg_distance_meters,
            s.problem_rate,
            ST_AsGeoJSON(d.geom) AS geojson
            FROM districts d
            LEFT JOIN district_session_stats s
                ON s.district_id = d.id AND s.session_id = ?
            ", [$session->id]);

        $features = array_map(function ($row) {
            return [
                'type' => 'Feature',
                'geometry' => json_decode($row->geojson),
                'properties' => [
                    'name' => $row->name,
                    'osm_id' => $row->osm_id,
                    'address_count' => $row->address_count !== null ? (int) $row->address_count : 0,
                    'avg_distance_meters' => $row->avg_distance_meters !== null ? (float) $row->avg_distance_meters : null,
                    'problem_rate' => $row->problem_rate !== null ? (float) $row->problem_rate : null,
                ],
            ];
        }, $rows);

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ])
            ->header('Content-Type', 'application/geo+json')
            ->header('Content-Disposition', 'attachment; filename="districts.geojson"');
    }

    public function exportPairs(GeocodingSession $session)
    {
        $rows = DB::select("
        SELECT
            a.raw_address,
            a.nominatim_display_name,
            a.photon_display_name,
            a.distance_meters,
            a.is_problem,
            ST_AsGeoJSON(a.nominatim_geom) AS nominatim_geojson,
            ST_AsGeoJSON(a.photon_geom)    AS photon_geojson,
            ST_AsGeoJSON(
                ST_MakeLine(a.nominatim_geom, a.photon_geom)
            ) AS line_geojson
        FROM addresses a
        WHERE a.session_id = ?
          AND a.nominatim_geom IS NOT NULL
          AND a.photon_geom IS NOT NULL
    ", [$session->id]);

        $features = [];

        foreach ($rows as $row) {
            $distance = $row->distance_meters !== null ? (float) $row->distance_meters : null;
            $isOutlier = $distance !== null && $distance > 1000;
            $isZero = $distance !== null && $distance == 0.0;

            if ($isZero) {
                // оба геокодера нашли одну точку — одна merged точка
                $features[] = [
                    'type'       => 'Feature',
                    'geometry'   => json_decode($row->nominatim_geojson),
                    'properties' => [
                        'type'                   => 'merged',
                        'raw_address'            => $row->raw_address,
                        'nominatim_display_name' => $row->nominatim_display_name,
                        'photon_display_name'    => $row->photon_display_name,
                        'distance_meters'        => $distance,
                        'is_problem'             => false,
                        'is_outlier'             => false,
                    ],
                ];
            } else {
                // две точки + линия
                $features[] = [
                    'type'       => 'Feature',
                    'geometry'   => json_decode($row->nominatim_geojson),
                    'properties' => [
                        'type'         => 'nominatim',
                        'raw_address'  => $row->raw_address,
                        'display_name' => $row->nominatim_display_name,
                        'distance_meters' => $distance,
                        'is_problem'   => (bool) $row->is_problem,
                        'is_outlier'   => $isOutlier,
                    ],
                ];

                $features[] = [
                    'type'       => 'Feature',
                    'geometry'   => json_decode($row->photon_geojson),
                    'properties' => [
                        'type'         => 'photon',
                        'raw_address'  => $row->raw_address,
                        'display_name' => $row->photon_display_name,
                        'distance_meters' => $distance,
                        'is_problem'   => (bool) $row->is_problem,
                        'is_outlier'   => $isOutlier,
                    ],
                ];

                $features[] = [
                    'type'       => 'Feature',
                    'geometry'   => json_decode($row->line_geojson),
                    'properties' => [
                        'type'            => 'line',
                        'raw_address'     => $row->raw_address,
                        'distance_meters' => $distance,
                        'is_problem'      => (bool) $row->is_problem,
                        'is_outlier'      => $isOutlier,
                    ],
                ];
            }
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ])
            ->header('Content-Type', 'application/geo+json')
            ->header('Content-Disposition', 'attachment; filename="pairs.geojson"');
    }

    public function coverage(GeocodingSession $session)
    {
        $stats = DB::selectOne("
        SELECT
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'geocoded' AND nominatim_geom IS NOT NULL AND photon_geom IS NOT NULL THEN 1 END) as both_geocoders,
            COUNT(CASE WHEN status = 'geocoded' AND (nominatim_geom IS NULL OR photon_geom IS NULL) THEN 1 END) as one_geocoder,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed
        FROM addresses
        WHERE session_id = ?
    ", [$session->id]);

        return response()->json([
            'total'          => (int) $stats->total,
            'both_geocoders' => (int) $stats->both_geocoders,
            'one_geocoder'   => (int) $stats->one_geocoder,
            'failed'         => (int) $stats->failed,
        ]);
    }

    public function report(GeocodingSession $session)
    {
        $rows = DB::select("
        SELECT
            a.raw_address,
            a.normalized_address,
            a.status,
            a.distance_meters,
            a.is_problem,
            a.nominatim_lat,
            a.nominatim_lon,
            a.photon_lat,
            a.photon_lon,
            a.nominatim_display_name,
            a.photon_display_name
        FROM addresses a
        WHERE a.session_id = ?
        ORDER BY
            CASE
                WHEN a.distance_meters IS NULL AND a.status = 'failed' THEN 4
                WHEN a.distance_meters IS NULL AND a.status = 'geocoded' THEN 3
                WHEN a.distance_meters > 1000 THEN 2
                ELSE 1
            END,
            a.distance_meters DESC NULLS LAST
    ", [$session->id]);

        return response()->json(array_map(function ($row) {
            $distance = $row->distance_meters !== null ? (float) $row->distance_meters : null;
            $isOutlier = $distance !== null && $distance > 1000;

            // определяем категорию
            if ($row->status === 'failed') {
                $category = 'not_found';
            } elseif ($distance === null) {
                $category = 'single';
            } elseif ($isOutlier) {
                $category = 'outlier';
            } elseif ($distance == 0.0) {
                $category = 'exact';
            } else {
                $category = 'divergent';
            }

            // какой геокодер нашёл при single
            $foundBy = null;
            if ($category === 'single') {
                if ($row->nominatim_lat !== null && $row->photon_lat === null) {
                    $foundBy = 'Nominatim';
                } elseif ($row->photon_lat !== null && $row->nominatim_lat === null) {
                    $foundBy = 'Photon';
                }
            }

            return [
                'raw_address'            => $row->raw_address,
                'normalized_address'     => $row->normalized_address,
                'category'               => $category,
                'distance_meters'        => $distance,
                'is_problem'             => (bool) $row->is_problem,
                'nominatim_display_name' => $row->nominatim_display_name,
                'photon_display_name'    => $row->photon_display_name,
                'found_by'               => $foundBy,
            ];
        }, $rows));
    }


}
