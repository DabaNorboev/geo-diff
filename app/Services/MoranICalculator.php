<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MoranICalculator
{
    /**
     * Глобальный индекс Морана по значениям районов внутри сессии.
     * Возвращает null, если данных недостаточно для содержательного расчёта
     * (меньше 3 районов с данными, нет соседства между ними, или нулевая дисперсия).
     */
    public function calculate(string $sessionId, string $metric = 'avg_distance_meters'): ?float
    {
        $stats = DB::table('district_session_stats')
            ->where('session_id', $sessionId)
            ->pluck($metric, 'district_id');

        $districtIds = $stats->keys()->all();
        $n = count($districtIds);

        if ($n < 3) {
            return null;
        }

        $values = $stats->map(fn ($v) => (float) $v);
        $mean = $values->avg();

        $placeholders = implode(',', array_fill(0, count($districtIds), '?'));
        $bindings = array_merge($districtIds, $districtIds);

        $pairs = DB::select("
            SELECT a.id AS a_id, b.id AS b_id
            FROM districts a, districts b
            WHERE a.id <> b.id
            AND a.id IN ($placeholders)
            AND b.id IN ($placeholders)
            AND ST_Touches(a.geom, b.geom)
        ", $bindings);

        $s0 = count($pairs);

        if ($s0 === 0) {
            return null; // ни один из районов с данными не граничит с другим
        }

        $numerator = 0.0;
        foreach ($pairs as $pair) {
            $xi = $values[$pair->a_id] - $mean;
            $xj = $values[$pair->b_id] - $mean;
            $numerator += $xi * $xj;
        }

        $denominator = $values->reduce(
            fn ($carry, $x) => $carry + ($x - $mean) ** 2,
            0.0
        );

        if ($denominator == 0.0) {
            return null; // все значения одинаковы — автокорреляция не определена
        }

        return ($n / $s0) * ($numerator / $denominator);
    }
}
