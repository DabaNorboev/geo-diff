<?php

namespace App\Http\Controllers;

use App\Jobs\GeocodeAddressJob;
use App\Models\Address;
use App\Models\GeocodingSession;
use App\Services\AddressSimplifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RetryController extends Controller
{
    public function __construct(private AddressSimplifier $simplifier)
    {
    }

    /**
     * Кандидаты на повторный поиск:
     *  - not_found — оба геокодера не нашли (status = failed)
     *  - single    — нашёл только один геокодер
     *  - outlier   — нашли оба, но расхождение > 1000м (вероятный ложный матч)
     *
     * Для not_found/single упрощение адреса предлагается, только если
     * AddressSimplifier реально нашёл что срезать. Для outlier — предлагаем
     * упрощённый вариант всегда (по требованию), даже если он совпадает
     * с исходным — решение всё равно принимает пользователь на фронте.
     */
    public function candidates(GeocodingSession $session)
    {
        $rows = DB::select("
            SELECT id, raw_address, normalized_address, status, distance_meters,
                   nominatim_lat, photon_lat
            FROM addresses
            WHERE session_id = ?
              AND (
                    status = 'failed'
                    OR (status = 'geocoded' AND (nominatim_lat IS NULL OR photon_lat IS NULL))
                    OR (status = 'geocoded' AND distance_meters > 1000)
              )
            ORDER BY raw_address
        ", [$session->id]);

        $candidates = array_map(function ($row) {
            $distance  = $row->distance_meters !== null ? (float) $row->distance_meters : null;
            $isOutlier = $distance !== null && $distance > 1000;

            $category = match (true) {
                $row->status === 'failed' => 'not_found',
                $isOutlier                => 'outlier',
                default                   => 'single',
            };

            $simplified = $this->simplifier->simplify($row->normalized_address);
            $suggested  = $simplified ?? $row->normalized_address;

            return [
                'id'                => $row->id,
                'raw_address'       => $row->raw_address,
                'current_address'   => $row->normalized_address,
                'suggested_address' => $suggested,
                'was_simplified'    => $simplified !== null,
                'category'          => $category,
                'distance_meters'   => $distance,
            ];
        }, $rows);

        return response()->json(['candidates' => $candidates]);
    }

    /**
     * Запускает повторный поиск для выбранных адресов с учётом
     * (возможно отредактированного пользователем на фронте) варианта адреса.
     */
    public function retry(Request $request, GeocodingSession $session)
    {
        $validated = $request->validate([
            'addresses'                   => 'required|array|min:1',
            'addresses.*.id'              => 'required|integer',
            'addresses.*.address_to_use'  => 'required|string|max:500',
        ]);

        $addressIds = collect($validated['addresses'])->pluck('id');

        $ownedCount = Address::where('session_id', $session->id)
            ->whereIn('id', $addressIds)
            ->count();

        if ($ownedCount !== $addressIds->count()) {
            return response()->json([
                'message' => 'Один или несколько адресов не принадлежат этой сессии',
            ], 422);
        }

        $retryCount = DB::transaction(function () use ($validated, $session) {
            $nextDelaySeconds = 0;
            $count = 0;

            foreach ($validated['addresses'] as $item) {
                // обычные колонки — через query builder, geometry — отдельно raw SQL
                // (по соглашению проекта: Eloquent не трогает geometry-колонки)
                DB::table('addresses')->where('id', $item['id'])->update([
                    'normalized_address'     => $item['address_to_use'],
                    'status'                 => 'pending',
                    'nominatim_lat'          => null,
                    'nominatim_lon'          => null,
                    'nominatim_display_name' => null,
                    'photon_lat'             => null,
                    'photon_lon'             => null,
                    'photon_display_name'    => null,
                    'distance_meters'        => null,
                    // is_problem — NOT NULL в схеме, null сюда ставить нельзя;
                    // false — нейтральное значение, пересчитается заново в handle(),
                    // если оба геокодера снова что-то найдут
                    'is_problem'             => false,
                    'district_id'            => null,
                    'error_message'          => null,
                    'updated_at'             => now(),
                ]);

                DB::statement(
                    'UPDATE addresses SET nominatim_geom = NULL, photon_geom = NULL WHERE id = ?',
                    [$item['id']]
                );

                $address = Address::find($item['id']);

                $delay = $this->isFullyCached($item['address_to_use']) ? 0 : $nextDelaySeconds;
                if (! $this->isFullyCached($item['address_to_use'])) {
                    $nextDelaySeconds++;
                }

                GeocodeAddressJob::dispatch($address)
                    ->delay(now()->addSeconds($delay))
                    ->afterCommit();

                $count++;
            }

            // возвращаем сессию в processing и уменьшаем счётчик обработанных —
            // когда повторные джобы отработают, finalizeSessionProgress() из
            // GeocodeAddressJob сам доведёт processed_addresses до total
            // и повторно запустит AggregateSessionStatsJob, без изменений там
            DB::update("
                UPDATE geocoding_sessions
                SET status = 'processing',
                    processed_addresses = GREATEST(processed_addresses - ?, 0)
                WHERE id = ?
            ", [$count, $session->id]);

            return $count;
        });

        return response()->json([
            'message' => 'Повторный поиск запущен',
            'count'   => $retryCount,
        ]);
    }

    private function isFullyCached(string $normalizedAddress): bool
    {
        $version = config('geocoding.cache_version', 1);
        $hash = md5($normalizedAddress);

        return Cache::has("geocode:v{$version}:nominatim:{$hash}")
            && Cache::has("geocode:v{$version}:photon:{$hash}");
    }
}
