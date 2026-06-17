<?php

namespace ServerPulse\Agent\Collectors;

use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Horizon;
use Laravel\Octane\Octane;

class LaravelCollector extends BaseCollector
{
    public function key(): string
    {
        return 'laravel';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function doCollect(array $config): array
    {
        return [
            'app_env' => app()->environment(),
            'app_debug' => config('app.debug'),
            'laravel_version' => app()->version(),
            'php_framework' => 'laravel',
            'queue_driver' => config('queue.default'),
            'queue_pending' => $this->getPendingJobs(),
            'queue_failed' => $this->getFailedJobs(),
            'cache_driver' => config('cache.default'),
            'session_driver' => config('session.driver'),
            'horizon_enabled' => class_exists(Horizon::class),
            'horizon_stats' => $this->getHorizonStats(),
            'octane_enabled' => class_exists(Octane::class),
            'recent_exceptions' => 0,
            'request_count_1m' => 0,
            'response_time_avg_1m' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function getPendingJobs(): array
    {
        try {
            $driver = config('queue.default');
            $connection = config("queue.connections.{$driver}");

            if (! is_array($connection)) {
                return [];
            }

            if (($connection['driver'] ?? '') === 'database') {
                $count = DB::table(config('queue.connections.database.table', 'jobs'))
                    ->count();

                return ['default' => $count];
            }
        } catch (\Throwable $e) {
            // silently fail
        }

        return [];
    }

    private function getFailedJobs(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getHorizonStats(): ?array
    {
        if (! class_exists(Horizon::class)) {
            return null;
        }

        return [];
    }
}
