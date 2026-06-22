<?php

namespace ServerPulse\Agent\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ServerPulse\Agent\Collectors\DomainCollector;
use ServerPulse\Agent\Services\ConfigService;

class RequestTaggingMiddleware
{
    private static int $requestCount = 0;

    private static float $totalResponseTime = 0;

    private static int $responseCount = 0;

    private ?string $heartbeatPath = null;

    public function handle(Request $request, Closure $next): mixed
    {
        $start = hrtime(true);

        try {
            self::$requestCount++;

            $this->maybeQueueHeartbeat();

            return $next($request);
        } catch (\Throwable $e) {
            return response('', 200);
        } finally {
            $elapsed = (hrtime(true) - $start) / 1e9;
            self::$totalResponseTime += $elapsed;
            self::$responseCount++;
        }
    }

    private function maybeQueueHeartbeat(): void
    {
        $heartbeatFile = $this->resolveHeartbeatPath();

        if (file_exists($heartbeatFile) && (time() - filemtime($heartbeatFile)) < 30) {
            return;
        }

        static $queued = false;

        if ($queued) {
            return;
        }

        $queued = true;

        $domainCollector = new DomainCollector;
        $domain = $domainCollector->collect([]);

        try {
            $config = app(ConfigService::class);
            $apiBase = $config->resolveApiBase();
        } catch (\Throwable $e) {
            $config = new ConfigService;
            $apiBase = $config->resolveApiBase();
        }

        register_shutdown_function(function () use ($heartbeatFile, $domain, $apiBase) {
            $this->sendHeartbeat($heartbeatFile, $domain, $apiBase);
        });
    }

    /**
     * @param  array<string, mixed>  $domain
     */
    private function sendHeartbeat(string $heartbeatFile, array $domain, string $apiBase): void
    {
        $lockFile = $heartbeatFile.'.lock';

        $lockDir = dirname($lockFile);
        if (! is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        $fp = @fopen($lockFile, 'c');

        if ($fp === false || ! flock($fp, LOCK_EX | LOCK_NB)) {
            if (is_resource($fp)) {
                fclose($fp);
            }

            return;
        }

        try {
            if (file_exists($heartbeatFile) && (time() - filemtime($heartbeatFile)) < 30) {
                return;
            }

            $payload = [
                'timestamp' => date('Y-m-d\TH:i:s\Z'),
                'agent_ver' => '1.0',
                'heartbeat' => true,
                'domain' => $domain,
            ];

            Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Agent-Version' => '1.0',
                'X-API-Key' => ConfigService::API_KEY,
            ])->withOptions(['timeout' => 5, 'connect_timeout' => 3])
                ->post($apiBase.'/v1/agent/report', $payload);

            $dir = dirname($heartbeatFile);

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            @touch($heartbeatFile);
        } catch (\Throwable $e) {
            // Never affect the host app
        } finally {
            if (is_resource($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    private function resolveHeartbeatPath(): string
    {
        if ($this->heartbeatPath !== null) {
            return $this->heartbeatPath;
        }

        if (function_exists('storage_path')) {
            return storage_path('framework/cache/serverpulse/.sp_heartbeat');
        }

        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'.sp_heartbeat';
    }

    // ── Test accessors ──

    /** @internal */
    public static function callReset(): void
    {
        self::$requestCount = 0;
        self::$totalResponseTime = 0;
        self::$responseCount = 0;
    }

    /** @internal */
    public function callGetRequestCount(): int
    {
        return self::$requestCount;
    }

    /** @internal */
    public function callGetAvgResponseTime(): float
    {
        if (self::$responseCount === 0) {
            return 0.0;
        }

        return self::$totalResponseTime / self::$responseCount;
    }

    /** @internal */
    public function callSetHeartbeatPath(string $path): void
    {
        $this->heartbeatPath = $path;
    }
}
