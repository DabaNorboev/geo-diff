<?php

namespace App\Jobs;

use App\Models\GeocodingSession;
use App\Services\MoranICalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AggregateSessionStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // адреса с расхождением выше порога считаются ложными совпадениями
    // (геокодеры нашли разные объекты, а не один адрес с погрешностью)
    private const MAX_VALID_DISTANCE = 1000;

    public function __construct(private string $sessionId)
    {
    }

    public function handle(MoranICalculator $moranCalculator): void
    {
        $this->aggregateDistrictStats();
        $this->aggregateSessionTotals();

        $moranI = $moranCalculator->calculate($this->sessionId);

        GeocodingSession::whereKey($this->sessionId)->update(['moran_i' => $moranI]);
    }

    private function aggregateDistrictStats(): void
    {
        DB::statement("
            INSERT INTO district_session_stats
                (session_id, district_id, address_count, avg_distance_meters, problem_rate, created_at, updated_at)
            SELECT
                ?,
                district_id,
                COUNT(*),
                AVG(distance_meters),
                AVG(CASE WHEN is_problem THEN 1 ELSE 0 END),
                NOW(), NOW()
            FROM addresses
            WHERE session_id = ?
              AND district_id IS NOT NULL
              AND distance_meters IS NOT NULL
              AND distance_meters <= ?
            GROUP BY district_id
            ON CONFLICT (session_id, district_id) DO UPDATE
            SET address_count = EXCLUDED.address_count,
                avg_distance_meters = EXCLUDED.avg_distance_meters,
                problem_rate = EXCLUDED.problem_rate,
                updated_at = NOW()
        ", [$this->sessionId, $this->sessionId, self::MAX_VALID_DISTANCE]);
    }

    private function aggregateSessionTotals(): void
    {
        $totals = DB::table('addresses')
            ->where('session_id', $this->sessionId)
            ->whereNotNull('distance_meters')
            ->where('distance_meters', '<=', self::MAX_VALID_DISTANCE)
            ->selectRaw('AVG(distance_meters) AS avg_distance, AVG(CASE WHEN is_problem THEN 1 ELSE 0 END) AS problem_rate')
            ->first();

        GeocodingSession::whereKey($this->sessionId)->update([
            'avg_distance_meters' => $totals->avg_distance,
            'problem_rate'        => $totals->problem_rate,
        ]);
    }
}

