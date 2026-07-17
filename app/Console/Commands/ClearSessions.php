<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ClearSessions extends Command
{
    protected $signature = 'sessions:clear {--with-cache : Очистить также кэш геокодирования}';
    protected $description = 'Очистка сессионных данных (addresses, district_session_stats, geocoding_sessions)';

    public function handle(): int
    {
        DB::statement('TRUNCATE addresses, district_session_stats, geocoding_sessions RESTART IDENTITY CASCADE');
        $this->info('Сессионные данные очищены.');

        if ($this->option('with-cache')) {
            Cache::flush();
            $this->info('Кэш геокодирования очищен.');
        }

        return self::SUCCESS;
    }
}
