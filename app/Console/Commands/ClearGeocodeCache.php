<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ClearGeocodeCache extends Command
{
    /**
     * php artisan geocode:cache-clear                    — очистить кэш обоих геокодеров
     * php artisan geocode:cache-clear --provider=photon   — очистить кэш только Photon
     * php artisan geocode:cache-clear --provider=nominatim
     */
    protected $signature = 'geocode:cache-clear {--provider= : nominatim|photon, по умолчанию оба}';

    protected $description = 'Точечно очищает Redis-кэш результатов геокодирования (не трогает остальной кэш Laravel)';

    public function handle(): int
    {
        $provider = $this->option('provider');

        if ($provider && !in_array($provider, ['nominatim', 'photon'], true)) {
            $this->error("Неизвестный провайдер '{$provider}'. Допустимые значения: nominatim, photon.");
            return self::FAILURE;
        }

        $prefix = config('cache.prefix', '');

        $pattern = $provider
            ? "{$prefix}geocode:v*:{$provider}:*"
            : "{$prefix}geocode:v*:*";

        $keys = Redis::connection('cache')->keys($pattern);

        if (empty($keys)) {
            $this->info("Подходящих ключей не найдено по паттерну '{$pattern}' — нечего удалять.");
            $this->warn('Если ожидали найти ключи — проверьте REDIS_CACHE_CONNECTION и CACHE_PREFIX в .env,');
            $this->warn('возможный служебный префикс соединения тоже влияет на итоговый ключ.');
            return self::SUCCESS;
        }

        Redis::connection('cache')->del($keys);

        $this->info(count($keys) . ' ключ(ей) кэша геокодирования удалено' . ($provider ? " (провайдер: {$provider})" : ' (оба провайдера)') . '.');

        return self::SUCCESS;
    }
}
