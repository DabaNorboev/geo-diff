<?php

namespace App\Console\Commands;

use App\Models\District;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportDistricts extends Command
{
    protected $signature = 'districts:import';
    protected $description = 'Импорт административных районов Красноярска (admin_level=8) из OSM';

    public function handle(): int
    {
        $this->info('Запрашиваю список районов через Overpass...');

        $overpassQuery = <<<QUERY
        [out:json][timeout:180];
        relation["admin_level"="9"]["boundary"="administrative"](55.85,92.55,56.35,93.35);
        out tags;
        QUERY;

        $response = Http::withHeaders([
            'User-Agent' => 'GeoDiff-Diploma/1.0 (educational project)',
        ])
            ->asForm()
            ->timeout(60)
            ->post('https://overpass-api.de/api/interpreter', ['data' => $overpassQuery]);

        if (! $response->successful()) {
            $this->error('Overpass API вернул ошибку: ' . $response->status());
            return self::FAILURE;
        }

        $elements = $response->json('elements', []);

        if (empty($elements)) {
            $this->error('Overpass не вернул ни одного района. Проверь название города или admin_level.');
            return self::FAILURE;
        }

        $this->info('Найдено районов: ' . count($elements));

        foreach ($elements as $element) {
            $osmId = $element['id'];
            $name = $element['tags']['name'] ?? "Район {$osmId}";

            $this->line("Загружаю геометрию: {$name} (relation/{$osmId})");

            try {
                $geometry = $this->fetchGeometryFromNominatim($osmId);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $this->error("  Сетевая ошибка для {$name}: {$e->getMessage()}");
                $this->warn('  Пропускаю, попробуй перезапустить команду позже — она идемпотентна.');
                continue;
            }

            if (! $geometry) {
                $this->warn("  Не удалось получить геометрию для {$name}, пропускаю");
                continue;
            }

            DB::statement('
        INSERT INTO districts (osm_id, name, geom, created_at, updated_at)
        VALUES (?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), NOW(), NOW())
        ON CONFLICT (osm_id) DO UPDATE
        SET name = EXCLUDED.name, geom = EXCLUDED.geom, updated_at = NOW()
    ', [$osmId, $name, json_encode($geometry)]);

            sleep(1);
        }

        $this->info('Готово. Районов в базе: ' . District::count());

        return self::SUCCESS;
    }

    private function fetchGeometryFromNominatim(int $osmId): ?array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'GeoDiff-Diploma/1.0 (educational project)',
        ])
            ->timeout(45)
            ->retry(5, 3000)
            ->get('https://nominatim.openstreetmap.org/lookup', [
                'osm_ids' => "R{$osmId}",
                'format' => 'jsonv2',
                'polygon_geojson' => 1,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return $data[0]['geojson'] ?? null;
    }
}
