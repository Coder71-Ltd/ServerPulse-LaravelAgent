<?php

namespace ServerPulse\Agent\Collectors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Horizon;
use Laravel\Octane\Octane;
use ServerPulse\Agent\Middleware\RequestTaggingMiddleware;
use ServerPulse\Agent\Monolog\ServerPulseHandler;

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
        $requestCount = $this->getMiddlewareRequestCount();
        $avgResponseTime = $this->getMiddlewareAvgResponseTime();
        RequestTaggingMiddleware::callReset();

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
            'octane_worker_count' => $this->getOctaneWorkerCount(),
            'recent_exceptions' => $this->getRecentExceptions(),
            'request_count_1m' => $requestCount,
            'response_time_avg_1m' => $avgResponseTime,
        ];
    }

    private function getPendingJobs(): int
    {
        $driver = config('queue.default');

        if (in_array($driver, ['sync', 'null'], true)) {
            return 0;
        }

        try {
            if ($driver === 'database') {
                $table = config('queue.connections.database.table', 'jobs');
                $queue = config('queue.connections.database.queue', 'default');

                return DB::table($table)
                    ->where('queue', '=', $queue)
                    ->whereNull('reserved_at')
                    ->count();
            }

            return Queue::size();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getFailedJobs(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getOctaneWorkerCount(): ?int
    {
        if (! class_exists(Octane::class)) {
            return null;
        }

        try {
            $stateFile = storage_path('logs/octane-server-state.json');

            if (! file_exists($stateFile)) {
                return null;
            }

            $state = json_decode(file_get_contents($stateFile), true);

            return $state['state']['workers'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, int>|null
     */
    private function getHorizonStats(): ?array
    {
        if (! class_exists(Horizon::class)) {
            return null;
        }

        try {
            $redis = Redis::connection('horizon');
            $prefix = config('horizon.prefix', 'horizon:');

            $queueKeys = [];
            $cursor = 0;

            do {
                $result = $redis->scan($cursor, [
                    'MATCH' => $prefix.'queues:*',
                    'COUNT' => 100,
                ]);
                $cursor = $result[0];

                foreach ($result[1] as $key) {
                    if (
                        ! str_ends_with((string) $key, ':delayed')
                        && ! str_ends_with((string) $key, ':reserved')
                    ) {
                        $queueKeys[] = $key;
                    }
                }
            } while ($cursor != 0);

            $totalPending = 0;

            foreach ($queueKeys as $key) {
                $totalPending += $redis->llen($key);
            }

            return $totalPending > 0 ? ['horizon_pending' => $totalPending] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getMiddlewareRequestCount(): int
    {
        try {
            return RequestTaggingMiddleware::callGetRequestCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getMiddlewareAvgResponseTime(): float
    {
        try {
            return RequestTaggingMiddleware::callGetAvgResponseTime();
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function getRecentExceptions(): int
    {
        try {
            $handler = app(ServerPulseHandler::class);

            return $handler->getRecentExceptionCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
