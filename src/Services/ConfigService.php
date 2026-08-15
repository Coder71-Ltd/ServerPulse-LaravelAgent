<?php

namespace ServerPulse\Agent\Services;

use Illuminate\Support\Facades\Http;

class ConfigService
{
    private const CACHE_TTL = 300;

    /**
     * @var array<string, mixed>
     */
    private array $fallbackDefaults = [
        'enabled' => true,
        'blocked' => false,
        'log_paths' => [],
        'git_paths' => [],
        'collect' => [
            'server' => true,
            'web' => true,
            'php' => true,
            'database' => true,
            'git' => true,
            'logs' => true,
            'security' => true,
            'laravel' => true,
            'domain' => true,
        ],
    ];

    public function __construct(
        private readonly ?string $cachePath = null,
    ) {}

    private function resolveCachePath(): string
    {
        if ($this->cachePath !== null) {
            return $this->cachePath;
        }

        if (function_exists('storage_path')) {
            return storage_path('framework/cache/serverpulse/.sp_cache');
        }

        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'.sp_cache';
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $cached = $this->readCache();

        if ($cached !== null) {
            return $this->stripMetaKeys($cached);
        }

        try {
            $headers = [
                'X-Agent-Version' => '1.0',
                'X-Agent-Domain' => $this->resolveAgentDomain(),
            ];

            $apiKey = $this->resolveApiKey();

            if ($apiKey !== null) {
                $headers['X-API-Key'] = $apiKey;
            }

            $response = Http::withHeaders($headers)->get($this->resolveApiBase().'/v1/agent/config');

            if ($response->status() === 200) {
                $config = $response->json();

                if (isset($config['api_base_url'])) {
                    $config['__api_base_url'] = $config['api_base_url'];
                    unset($config['api_base_url']);
                }

                $this->writeCache($config);

                return $this->stripMetaKeys($config);
            }

            if ($response->status() === 410) {
                $config = ['enabled' => false];
                $this->writeCache($config);

                return $config;
            }

            // Non-200/410 response from API — use fallback defaults
            return $this->fallbackDefaults;
        } catch (\Throwable $e) {
            // Network error or exception — use stale cache if available, else fallback
            $stale = $this->readStaleCache();

            if ($stale !== null) {
                return $this->stripMetaKeys($stale);
            }

            return $this->fallbackDefaults;
        }
    }

    public function resolveApiBase(): string
    {
        $cached = $this->readStaleCache();

        if ($cached !== null && isset($cached['__api_base_url'])) {
            return $cached['__api_base_url'];
        }

        return config('services.serverpulse.api_base', 'https://api.serverpulse.io');
    }

    public function resolveAgentDomain(): string
    {
        $appUrlHost = $this->resolveAppUrlHost();

        if ($appUrlHost !== null) {
            return $appUrlHost;
        }

        if (! empty($_SERVER['HTTP_HOST'])) {
            return $_SERVER['HTTP_HOST'];
        }

        $hostname = gethostname();

        if ($hostname !== false && $hostname !== '') {
            return $hostname;
        }

        return 'unknown';
    }

    /**
     * Resolve the API key for outbound requests.
     *
     * The key is strictly optional — identity is the reported domain/URL/IP,
     * not the key. Priority: cached agent config `api_key` → env-derived
     * config value → null (no header sent).
     */
    public function resolveApiKey(): ?string
    {
        $cached = $this->readStaleCache();

        if (is_array($cached) && isset($cached['api_key']) && is_string($cached['api_key']) && $cached['api_key'] !== '') {
            return $cached['api_key'];
        }

        $configured = config('services.serverpulse.api_key');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return null;
    }

    /**
     * Derive the primary identifier host from the host app's configured URL.
     */
    private function resolveAppUrlHost(): ?string
    {
        $appUrl = config('app.url');

        if (! is_string($appUrl) || $appUrl === '') {
            return null;
        }

        $host = parse_url($appUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return $host;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function stripMetaKeys(array $config): array
    {
        unset($config['__api_base_url']);

        return $config;
    }

    public function markDisabled(): void
    {
        $this->writeCache(['enabled' => false]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        $path = $this->resolveCachePath();

        if (! file_exists($path)) {
            return null;
        }

        $age = time() - filemtime($path);

        if ($age >= self::CACHE_TTL) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return json_decode($contents, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStaleCache(): ?array
    {
        $path = $this->resolveCachePath();

        if (! file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return json_decode($contents, true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function writeCache(array $config): void
    {
        $path = $this->resolveCachePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tempPath = $path.'.tmp';
        file_put_contents($tempPath, json_encode($config, JSON_UNESCAPED_SLASHES));
        rename($tempPath, $path);
    }
}
