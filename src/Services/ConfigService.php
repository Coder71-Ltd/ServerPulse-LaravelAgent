<?php

namespace ServerPulse\Agent\Services;

use Illuminate\Support\Facades\Http;

class ConfigService
{
    public const API_BASE = 'https://serverpulse.coder71.com';

    public const API_KEY = 'sp_dev_agent_key_001';

    private const CACHE_TTL = 300;

    /**
     * @var array<string, mixed>
     */
    private array $fallbackDefaults = [
        'enabled' => true,
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
            $response = Http::withHeaders([
                'X-Agent-Version' => '1.0',
                'X-API-Key' => self::API_KEY,
            ])->get($this->resolveApiBase().'/v1/agent/config');

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
        } catch (\Throwable $e) {
            // fall through to fallback
        }

        $stale = $this->readStaleCache();

        if ($stale !== null) {
            return $this->stripMetaKeys($stale);
        }

        return $this->fallbackDefaults;
    }

    public function resolveApiBase(): string
    {
        $cached = $this->readStaleCache();

        if ($cached !== null && isset($cached['__api_base_url'])) {
            return $cached['__api_base_url'];
        }

        return self::API_BASE;
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
